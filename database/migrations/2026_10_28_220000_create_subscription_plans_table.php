<?php

use App\Support\SubscriptionPlanCatalog;
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
        foreach (SubscriptionPlanCatalog::baselines() as $index => $plan) {
            DB::table('subscription_plans')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $plan['name'],
                'slug' => $plan['slug'],
                'description' => $plan['description'],
                'price_per_room' => $plan['price_per_room'],
                'currency' => 'PHP',
                'max_rooms' => $plan['max_rooms'],
                'modules' => json_encode([]),
                'sort_order' => $index + 1,
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
