<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Support\SubscriptionPlanCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Upsert the fixed room-band rates and deactivate any other active baselines.
     */
    public function run(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $now = now();
        $keptKeys = [];

        foreach (SubscriptionPlanCatalog::baselines() as $index => $plan) {
            $key = $plan['max_rooms'] === null ? 'uncapped' : (string) $plan['max_rooms'];
            $keptKeys[$key] = true;

            $existing = SubscriptionPlan::query()
                ->when(
                    $plan['max_rooms'] === null,
                    fn ($query) => $query->whereNull('max_rooms'),
                    fn ($query) => $query->where('max_rooms', $plan['max_rooms']),
                )
                ->orderByDesc('is_active')
                ->orderBy('sort_order')
                ->first();

            $attributes = [
                'name' => $plan['name'],
                'slug' => $this->uniqueSlug($plan['slug'], $existing?->uuid),
                'description' => $plan['description'],
                'price_per_room' => $plan['price_per_room'],
                'currency' => 'PHP',
                'max_rooms' => $plan['max_rooms'],
                'modules' => [],
                'sort_order' => $index + 1,
                'is_active' => true,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('subscription_plans', 'base_price')) {
                $attributes['base_price'] = $existing->base_price ?? 0;
            }

            if ($existing) {
                $existing->fill($attributes)->save();
                continue;
            }

            SubscriptionPlan::query()->create([
                ...$attributes,
                'created_at' => $now,
            ]);
        }

        SubscriptionPlan::query()
            ->where('is_active', true)
            ->get()
            ->each(function (SubscriptionPlan $plan) use ($keptKeys): void {
                $key = $plan->max_rooms === null ? 'uncapped' : (string) $plan->max_rooms;
                if (! isset($keptKeys[$key])) {
                    $plan->update(['is_active' => false]);
                }
            });
    }

    private function uniqueSlug(string $preferred, ?string $ignoreUuid = null): string
    {
        $base = Str::slug($preferred) ?: 'plan';
        $slug = $base;
        $i = 2;

        while (
            DB::table('subscription_plans')
                ->where('slug', $slug)
                ->when($ignoreUuid, fn ($query) => $query->where('uuid', '!=', $ignoreUuid))
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
