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
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->decimal('base_price', 10, 2)->default(0)->after('price_per_room');
        });

        Schema::table('merchant_subscriptions', function (Blueprint $table): void {
            $table->string('status', 24)->default('active')->after('subscription_plan_uuid');
            $table->date('current_period_start')->nullable()->after('status');
            $table->date('current_period_end')->nullable()->after('current_period_start');
        });

        Schema::create('subscription_features', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('monthly_price', 10, 2);
            $table->string('currency', 8)->default('PHP');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_feature_toggles', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid');
            $table->string('feature_key');
            $table->boolean('enabled')->default(false);
            $table->decimal('price_at_activation', 10, 2)->default(0);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_uuid', 'feature_key']);
        });

        Schema::create('subscription_usage_units', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid');
            $table->string('resource_type', 32);
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['user_uuid', 'resource_type']);
        });

        $now = now();
        foreach ([
            ['sms_notifications', 'SMS Notifications', 'Text alerts for new bookings, check-in, and check-out.', 500, 1],
            ['advanced_analytics', 'Advanced Analytics', 'Extra revenue and occupancy reports on the analytics page.', 800, 2],
        ] as [$key, $name, $description, $price, $order]) {
            DB::table('subscription_features')->insert([
                'uuid' => (string) Str::uuid(),
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'monthly_price' => $price,
                'currency' => 'PHP',
                'is_active' => true,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage_units');
        Schema::dropIfExists('subscription_feature_toggles');
        Schema::dropIfExists('subscription_features');

        Schema::table('merchant_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['status', 'current_period_start', 'current_period_end']);
        });

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('base_price');
        });
    }
};
