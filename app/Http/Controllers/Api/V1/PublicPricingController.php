<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionBillingSetting;
use App\Models\SubscriptionPlan;
use App\Support\SubscriptionPlanCatalog;
use Illuminate\Http\JsonResponse;

class PublicPricingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->whereNotNull('max_rooms')
            ->orderBy('max_rooms')
            ->get(['uuid', 'name', 'slug', 'price_per_room', 'currency', 'max_rooms', 'description']);

        if ($plans->isEmpty()) {
            $plans = collect(SubscriptionPlanCatalog::baselines())
                ->filter(fn (array $plan) => $plan['max_rooms'] !== null)
                ->map(fn (array $plan) => (object) [
                    'uuid' => null,
                    'name' => $plan['name'],
                    'slug' => $plan['slug'],
                    'price_per_room' => $plan['price_per_room'],
                    'currency' => 'PHP',
                    'max_rooms' => $plan['max_rooms'],
                    'description' => $plan['description'],
                ]);
        }

        $uncapped = SubscriptionPlan::query()
            ->where('is_active', true)
            ->whereNull('max_rooms')
            ->orderBy('sort_order')
            ->first(['uuid', 'name', 'slug', 'price_per_room', 'currency', 'max_rooms', 'description']);

        if (! $uncapped) {
            $fallback = collect(SubscriptionPlanCatalog::baselines())
                ->first(fn (array $plan) => $plan['max_rooms'] === null);
            $uncapped = $fallback ? (object) [
                'uuid' => null,
                'name' => $fallback['name'],
                'slug' => $fallback['slug'],
                'price_per_room' => $fallback['price_per_room'],
                'currency' => 'PHP',
                'max_rooms' => null,
                'description' => $fallback['description'],
            ] : null;
        }

        $mapPlan = static fn ($plan): array => [
            'id' => $plan->uuid,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'pricePerRoom' => (float) $plan->price_per_room,
            'currency' => $plan->currency ?: 'PHP',
            'maxRooms' => $plan->max_rooms === null ? null : (int) $plan->max_rooms,
            'description' => $plan->description,
        ];

        $billing = SubscriptionBillingSetting::current();

        return response()->json([
            'currency' => 'PHP',
            'billingCycles' => $billing->billingCycles(),
            'bands' => $plans->map($mapPlan)->values(),
            'uncapped' => $uncapped ? $mapPlan($uncapped) : null,
        ]);
    }
}
