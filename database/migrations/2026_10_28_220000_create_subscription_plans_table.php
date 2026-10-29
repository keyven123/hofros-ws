<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->decimal('price_per_room', 10, 2)->default(0);
            $table->string('currency', 8)->default('PHP');
            $table->unsignedInteger('max_rooms')->nullable();
            $table->json('modules');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('merchant_subscriptions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->unique();
            $table->uuid('subscription_plan_uuid')->index();
            $table->timestamps();
        });

        $now = now();
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'A starting plan for a new property.',
                'price_per_room' => 0,
                'max_rooms' => 2,
                'modules' => ['dashboard', 'calendar', 'bookings', 'configuration'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Regular',
                'slug' => 'regular',
                'description' => 'Reservations, discounts, and guest messages.',
                'price_per_room' => 149,
                'max_rooms' => 8,
                'modules' => ['dashboard', 'calendar', 'bookings', 'configuration', 'discount', 'messages'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Advance',
                'slug' => 'advance',
                'description' => 'Adds customers, analytics, and the booking website.',
                'price_per_room' => 349,
                'max_rooms' => 25,
                'modules' => ['dashboard', 'calendar', 'bookings', 'configuration', 'discount', 'messages', 'customers', 'analytics', 'portals'],
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Every module, with no room cap.',
                'price_per_room' => 699,
                'max_rooms' => null,
                'modules' => ['dashboard', 'calendar', 'bookings', 'configuration', 'discount', 'messages', 'customers', 'analytics', 'portals', 'advanced'],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('subscription_plans')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $plan['name'],
                'slug' => $plan['slug'],
                'description' => $plan['description'],
                'price_per_room' => $plan['price_per_room'],
                'currency' => 'PHP',
                'max_rooms' => $plan['max_rooms'],
                'modules' => json_encode($plan['modules']),
                'sort_order' => $plan['sort_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
