<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionFeatureToggle;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminSubscriptionController extends Controller
{
    public function __construct(protected SubscriptionBillingService $billing)
    {
    }
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function storePlan(Request $request): JsonResponse
    {
        $validated = $this->validatedPlan($request);
        $validated['name'] = $this->baselineName($validated);
        if ($this->duplicateMaximum($validated['max_rooms'] ?? null)) {
            return $this->duplicateMaximumResponse();
        }
        $slug = $this->uniqueSlug($validated['name']);
        if ($slug === null) {
            return $this->duplicateName();
        }

        $nextOrder = (int) SubscriptionPlan::query()->max('sort_order') + 1;

        SubscriptionPlan::query()->create([
            ...$this->planAttributes($validated, $slug, true),
            'currency' => 'PHP',
            'sort_order' => $nextOrder,
            'is_active' => true,
        ]);

        return response()->json($this->payload(), 201);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $validated = $this->validatedPlan($request);
        $validated['name'] = $this->baselineName($validated, $plan->name);
        if ($this->duplicateMaximum($validated['max_rooms'] ?? null, $plan->uuid)) {
            return $this->duplicateMaximumResponse();
        }
        $slug = $this->uniqueSlug($validated['name'], $plan->uuid);
        if ($slug === null) {
            return $this->duplicateName();
        }

        $maxRooms = $validated['max_rooms'] ?? null;
        if ($maxRooms !== null) {
            $over = User::query()
                ->merchants()
                ->whereHas('subscription', fn ($query) => $query->where('subscription_plan_uuid', $plan->uuid))
                ->withCount('units')
                ->get()
                ->first(fn (User $user) => $user->units_count > $maxRooms);

            if ($over) {
                return response()->json([
                    'message' => $over->merchant_name.' has '.$over->units_count.' rooms, which is above a limit of '.$maxRooms.'.',
                ], 422);
            }
        }

        $plan->update($this->planAttributes($validated, $slug));

        return response()->json($this->payload());
    }

    public function updateFeatures(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'features' => ['required', 'array', 'min:1'],
            'features.*.key' => ['required', 'string', 'max:64'],
            'features.*.monthly_price' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated['features'] as $row) {
            SubscriptionFeature::query()
                ->where('key', $row['key'])
                ->where('is_active', true)
                ->update(['monthly_price' => $row['monthly_price']]);
        }

        return response()->json($this->payload());
    }

    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id' => ['required', 'uuid'],
            'plan_id' => ['required', 'uuid'],
        ]);

        $merchant = User::query()
            ->where('uuid', $validated['merchant_id'])
            ->merchants()
            ->withCount('units')
            ->first();

        $plan = SubscriptionPlan::query()->where('uuid', $validated['plan_id'])->where('is_active', true)->first();

        if (! $merchant || ! $plan) {
            return response()->json([
                'message' => 'Choose a merchant and a subscription.',
            ], 422);
        }

        $rooms = (int) $merchant->units_count;
        if ($plan->max_rooms !== null && $rooms > $plan->max_rooms) {
            return response()->json([
                'message' => $plan->name.' allows '.$plan->max_rooms.' rooms. '.$merchant->merchant_name.' has '.$rooms.' rooms.',
            ], 422);
        }

        $this->billing->startSubscription($merchant, $plan);

        return response()->json($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlan(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:240'],
            'price_per_room' => ['required', 'numeric', 'min:0'],
            'max_rooms' => ['nullable', 'integer', 'min:1'],
            'modules' => ['sometimes', 'array', 'min:1'],
            'modules.*' => ['string', Rule::in(array_keys(SubscriptionPlan::MODULES))],
        ]);
    }

    private function uniqueSlug(string $name, ?string $ignoreUuid = null): ?string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            return null;
        }

        $exists = SubscriptionPlan::query()
            ->where('slug', $slug)
            ->when($ignoreUuid, fn ($query) => $query->where('uuid', '!=', $ignoreUuid))
            ->exists();

        return $exists ? null : $slug;
    }

    private function duplicateName(): JsonResponse
    {
        return response()->json([
            'message' => 'A subscription with this name already exists.',
            'errors' => ['name' => ['A subscription with this name already exists.']],
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function planAttributes(array $validated, string $slug, bool $creating = false): array
    {
        $attributes = [
            'name' => trim($validated['name']),
            'slug' => $slug,
            'price_per_room' => $validated['price_per_room'],
            'max_rooms' => $validated['max_rooms'] ?? null,
        ];

        if (array_key_exists('description', $validated)) {
            $attributes['description'] = trim((string) $validated['description']);
        }

        if ($creating && ! array_key_exists('modules', $validated)) {
            $attributes['modules'] = [];
        }

        if (array_key_exists('modules', $validated)) {
            $attributes['modules'] = array_values(array_unique($validated['modules']));
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baselineName(array $validated, ?string $existing = null): string
    {
        $name = trim((string) ($validated['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if ($existing) {
            return $existing;
        }

        $maxRooms = $validated['max_rooms'] ?? null;

        return $maxRooms === null ? 'No room cap' : 'Up to '.$maxRooms.' rooms';
    }

    private function duplicateMaximum(mixed $maxRooms, ?string $ignoreUuid = null): bool
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->when($ignoreUuid, fn ($query) => $query->where('uuid', '!=', $ignoreUuid))
            ->when(
                $maxRooms === null,
                fn ($query) => $query->whereNull('max_rooms'),
                fn ($query) => $query->where('max_rooms', $maxRooms),
            )
            ->exists();
    }

    private function duplicateMaximumResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'A baseline for this room maximum already exists.',
            'errors' => ['max_rooms' => ['A baseline for this room maximum already exists.']],
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => $this->planPayload($plan));

        $enabledByMerchant = SubscriptionFeatureToggle::query()
            ->where('enabled', true)
            ->whereIn('feature_key', SubscriptionFeature::query()->where('is_active', true)->pluck('key'))
            ->get()
            ->groupBy('user_uuid');

        $merchants = User::query()
            ->merchants()
            ->withCount('units')
            ->orderBy('merchant_name')
            ->get()
            ->map(function (User $user) use ($enabledByMerchant) {
                $invoice = $this->billing->calculateNextInvoice($user->uuid);
                $roomItem = collect($invoice['items'])->firstWhere('type', 'rooms');
                $rooms = (int) ($roomItem['quantity'] ?? $user->units_count);
                $plan = $user->subscription()->first()?->plan;
                $activeModules = $enabledByMerchant->get($user->uuid, collect())->count();

                return [
                    'id' => $user->uuid,
                    'name' => $user->merchant_name,
                    'email' => $user->email,
                    'rooms' => $rooms,
                    'activeModules' => $activeModules,
                    'plan' => $plan ? [
                        'id' => $plan->uuid,
                        'name' => $plan->name,
                        'pricePerRoom' => (float) $plan->price_per_room,
                        'basePrice' => (float) ($plan->base_price ?? 0),
                        'currency' => $plan->currency,
                        'maxRooms' => $plan->max_rooms,
                        'modules' => $plan->modules ?? [],
                        'monthly' => $invoice['total'],
                    ] : null,
                ];
            });

        $features = SubscriptionFeature::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionFeature $feature) => [
                'key' => $feature->key,
                'name' => $feature->name,
                'description' => $feature->description,
                'monthlyPrice' => (float) $feature->monthly_price,
                'currency' => $feature->currency,
            ]);

        return [
            'modules' => collect(SubscriptionPlan::MODULES)->map(fn (string $label, string $id) => [
                'id' => $id,
                'label' => $label,
            ])->values(),
            'features' => $features,
            'plans' => $plans,
            'merchants' => $merchants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planPayload(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->uuid,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'pricePerRoom' => (float) $plan->price_per_room,
            'currency' => $plan->currency,
            'maxRooms' => $plan->max_rooms,
            'modules' => $plan->modules ?? [],
        ];
    }
}
