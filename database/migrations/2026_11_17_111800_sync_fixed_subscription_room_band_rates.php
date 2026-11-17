<?php

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Lock the admin room-band rates as the standard pricing for every environment.
     */
    public function up(): void
    {
        (new SubscriptionPlanSeeder)->run();
    }

    public function down(): void
    {
        // Rates are canonical product data; no automatic restore of older named tiers.
    }
};
