<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionBillingSetting;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionFeatureToggle;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUsageUnit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SubscriptionBillingService
{
    public function freeTrialDays(): int
    {
        try {
            return SubscriptionBillingSetting::current()->freeTrialDays();
        } catch (\Throwable) {
            return GeneralConstants::FREE_TRIAL_DAYS;
        }
    }

    public function startFreeTrial(User $merchant): ?MerchantSubscription
    {
        $trialDays = $this->freeTrialDays();
        $trialEnds = ($merchant->created_at ?? now())->copy()->startOfDay()->addDays($trialDays);
        $merchant->forceFill([
            'trial_ends_at' => $trialEnds->toDateString(),
            'account_status' => 'active',
        ])->save();

        $plan = $this->baselineForRoomCount(0);
        if (! $plan) {
            return null;
        }

        $subscription = MerchantSubscription::query()->updateOrCreate(
            ['user_uuid' => $merchant->uuid],
            [
                'subscription_plan_uuid' => $plan->uuid,
                'status' => 'trialing',
                'current_period_start' => now()->toDateString(),
                'current_period_end' => $trialEnds->toDateString(),
            ],
        );

        $this->syncRoomUsage($merchant);
        $this->activateAllFeatures($merchant->uuid);

        return $subscription;
    }

    public function startSubscription(User $merchant, SubscriptionPlan $plan): MerchantSubscription
    {
        $existing = MerchantSubscription::query()->where('user_uuid', $merchant->uuid)->first();
        $periodOpen = $existing?->current_period_end instanceof Carbon
            && $existing->current_period_end->gte(now()->startOfDay());

        $subscription = MerchantSubscription::query()->updateOrCreate(
            ['user_uuid' => $merchant->uuid],
            [
                'subscription_plan_uuid' => $plan->uuid,
                'status' => 'active',
                'current_period_start' => $periodOpen ? $existing->current_period_start : now()->toDateString(),
                'current_period_end' => $periodOpen ? $existing->current_period_end : now()->addMonth()->toDateString(),
            ],
        );

        $this->syncRoomUsage($merchant);

        return $subscription;
    }

    public function toggleFeature(string $merchantId, string $featureKey, bool $enabled): SubscriptionFeatureToggle
    {
        $this->ensureBaseline($merchantId);

        $feature = SubscriptionFeature::query()
            ->where('key', $featureKey)
            ->where('is_active', true)
            ->first();

        if (! $feature) {
            throw ValidationException::withMessages([
                'feature_key' => 'That feature is not available.',
            ]);
        }

        $toggle = SubscriptionFeatureToggle::query()->firstOrNew([
            'user_uuid' => $merchantId,
            'feature_key' => $feature->key,
        ]);

        $toggle->enabled = $enabled;
        if ($enabled) {
            $toggle->price_at_activation = $feature->monthly_price;
            $toggle->enabled_at = now();
            $toggle->disabled_at = null;
        } else {
            $toggle->disabled_at = now();
        }
        $toggle->save();

        return $toggle;
    }

    public function updateUnitUsage(string $merchantId, string $resourceType, int $newCount): SubscriptionUsageUnit
    {
        return SubscriptionUsageUnit::query()->updateOrCreate(
            [
                'user_uuid' => $merchantId,
                'resource_type' => $resourceType,
            ],
            [
                'quantity' => max(0, $newCount),
            ],
        );
    }

    public function syncRoomUsage(User|string $merchant): int
    {
        $merchantId = $merchant instanceof User ? $merchant->uuid : $merchant;
        $count = Unit::query()
            ->where('user_uuid', $merchantId)
            ->where('status', Unit::STATUS_ACTIVE)
            ->count();

        $this->updateUnitUsage($merchantId, SubscriptionUsageUnit::ROOMS, $count);

        return $count;
    }

    /**
     * The baseline is the room band that covers the merchant's active rooms:
     * the smallest maximum that still fits, or the band with no room cap.
     */
    public function baselineForRoomCount(int $rooms): ?SubscriptionPlan
    {
        $plans = SubscriptionPlan::query()->where('is_active', true)->get();
        $capped = $plans
            ->filter(fn (SubscriptionPlan $plan) => $plan->max_rooms !== null)
            ->sortBy([
                ['max_rooms', 'asc'],
                ['price_per_room', 'asc'],
            ]);

        $match = $capped->first(fn (SubscriptionPlan $plan) => $rooms <= (int) $plan->max_rooms);
        if ($match) {
            return $match;
        }

        return $plans->first(fn (SubscriptionPlan $plan) => $plan->max_rooms === null);
    }

    public function ensureBaseline(string $merchantId): MerchantSubscription
    {
        $merchant = User::query()->where('uuid', $merchantId)->first();
        if (! $merchant) {
            throw ValidationException::withMessages([
                'subscription' => 'Subscribe to a plan before managing billing.',
            ]);
        }

        $rooms = $this->syncRoomUsage($merchant);
        $plan = $this->baselineForRoomCount($rooms);
        if (! $plan) {
            throw ValidationException::withMessages([
                'subscription' => 'Add a room baseline before billing can be calculated.',
            ]);
        }

        return $this->startSubscription($merchant, $plan);
    }

    /**
     * Next invoice is the matching room baseline plus each module the merchant has turned on.
     * Mid-cycle changes are not pro-rated. They apply in full on the next invoice.
     *
     * @return array<string, mixed>
     */
    public function calculateNextInvoice(string $merchantId): array
    {
        $subscription = $this->ensureBaseline($merchantId);
        $this->ensureOpenPeriod($subscription);
        $subscription->load('plan');

        $plan = $subscription->plan;
        if (! $plan) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription has no plan.',
            ]);
        }

        $merchant = User::query()->where('uuid', $merchantId)->firstOrFail();
        $this->activateAllFeatures($merchantId);

        $rooms = $this->syncRoomUsage($merchantId);
        $unitPrice = round((float) $plan->price_per_room, 2);
        $roomAmount = round($rooms * $unitPrice, 2);

        $toggles = SubscriptionFeatureToggle::query()
            ->where('user_uuid', $merchantId)
            ->where('enabled', true)
            ->get();

        $features = SubscriptionFeature::query()
            ->where('is_active', true)
            ->whereIn('key', $toggles->pluck('feature_key'))
            ->get()
            ->keyBy('key');

        $roomLabel = $plan->max_rooms === null
            ? 'Rooms'
            : 'Rooms · maximum '.$plan->max_rooms;

        $items = [
            [
                'type' => 'rooms',
                'label' => $roomLabel,
                'quantity' => $rooms,
                'unitPrice' => $unitPrice,
                'amount' => $roomAmount,
            ],
        ];

        $addonTotal = 0.0;
        foreach ($features->sortBy('sort_order') as $feature) {
            $amount = round((float) $feature->monthly_price, 2);
            $addonTotal += $amount;
            $items[] = [
                'type' => 'addon',
                'key' => $feature->key,
                'label' => $feature->name,
                'quantity' => 1,
                'unitPrice' => $amount,
                'amount' => $amount,
            ];
        }

        $dueOn = $this->openDueDate($merchant);
        $onTrial = $merchant->onFreeTrial();
        $overdue = ! $onTrial && now()->startOfDay()->gt($dueOn);
        $merchant->account_status = $overdue ? 'suspended' : 'active';
        $merchant->save();

        $postTrialTotal = round($roomAmount + $addonTotal, 2);
        $trialDaysRemaining = $merchant->trialDaysRemaining();

        return [
            'currency' => $plan->currency ?: 'PHP',
            'registeredOn' => $merchant->created_at?->toDateString(),
            'dueOn' => $dueOn->toDateString(),
            'overdue' => $overdue,
            'accountStatus' => $merchant->account_status,
            'periodStart' => $this->dateString($subscription->current_period_start),
            'periodEnd' => $this->dateString($subscription->current_period_end),
            'prorated' => false,
            'maxRooms' => $plan->max_rooms,
            'pricePerRoom' => $unitPrice,
            'note' => $onTrial
                ? 'Your free trial includes all modules. Billing starts when the trial ends.'
                : 'Every active module is included. The due date is one month after registration, then the same date each month.',
            'items' => $items,
            'roomsSubtotal' => $onTrial ? 0.0 : $roomAmount,
            'modulesSubtotal' => $onTrial ? 0.0 : round($addonTotal, 2),
            'total' => $onTrial ? 0.0 : $postTrialTotal,
            'trial' => [
                'active' => $onTrial,
                'totalDays' => $this->resolveTrialTotalDays($merchant),
                'daysRemaining' => $trialDaysRemaining,
                'endsOn' => $this->dateString($merchant->trial_ends_at),
                'startedOn' => $merchant->created_at?->toDateString(),
            ],
            'postTrialEstimate' => [
                'roomsSubtotal' => $roomAmount,
                'modulesSubtotal' => round($addonTotal, 2),
                'total' => $postTrialTotal,
            ],
        ];
    }

    /**
     * Record one activation per module for this merchant. Every active module is billed.
     */
    public function activateAllFeatures(string $merchantId): void
    {
        $features = SubscriptionFeature::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($features as $feature) {
            $toggle = SubscriptionFeatureToggle::query()->firstOrNew([
                'user_uuid' => $merchantId,
                'feature_key' => $feature->key,
            ]);

            $toggle->enabled = true;
            $toggle->price_at_activation = $feature->monthly_price;
            $toggle->enabled_at = $toggle->enabled_at ?: now();
            $toggle->disabled_at = null;
            $toggle->save();
        }
    }

    public function openDueDate(User $user): Carbon
    {
        $lastPaid = SubscriptionPayment::query()
            ->where('user_uuid', $user->uuid)
            ->orderByDesc('due_on')
            ->first();

        if ($lastPaid?->due_on instanceof \DateTimeInterface) {
            return Carbon::parse($lastPaid->due_on)->startOfDay()->addMonth();
        }

        if ($user->trial_ends_at instanceof \DateTimeInterface) {
            return $user->trial_ends_at->copy()->startOfDay();
        }

        $anchor = ($user->created_at ?? now())->copy()->startOfDay();

        return $anchor->copy()->addMonth();
    }

    /**
     * @return array<string, mixed>
     */
    public function pay(User $merchant): array
    {
        $invoice = $this->calculateNextInvoice($merchant->uuid);
        $due = Carbon::parse($invoice['dueOn'])->startOfDay();

        if (now()->startOfDay()->gt($due)) {
            while ($due->copy()->addMonth()->startOfDay()->lte(now()->startOfDay())) {
                $due->addMonth();
            }
        }

        SubscriptionPayment::query()->updateOrCreate(
            [
                'user_uuid' => $merchant->uuid,
                'due_on' => $due->toDateString(),
            ],
            [
                'amount' => $invoice['total'],
                'currency' => $invoice['currency'],
                'paid_at' => now(),
            ],
        );

        return $this->calculateNextInvoice($merchant->uuid);
    }

    private function ensureOpenPeriod(MerchantSubscription $subscription): void
    {
        $end = $subscription->current_period_end;
        if ($subscription->current_period_start && $end instanceof Carbon && $end->gte(now()->startOfDay())) {
            return;
        }

        $subscription->status = 'active';
        $subscription->current_period_start = now()->toDateString();
        $subscription->current_period_end = now()->addMonth()->toDateString();
        $subscription->save();
    }

    private function resolveTrialTotalDays(User $merchant): int
    {
        if ($merchant->trial_ends_at instanceof \DateTimeInterface && $merchant->created_at instanceof \DateTimeInterface) {
            $span = (int) $merchant->created_at->copy()->startOfDay()
                ->diffInDays($merchant->trial_ends_at->copy()->startOfDay());

            if ($span > 0) {
                return $span;
            }
        }

        return $this->freeTrialDays();
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
