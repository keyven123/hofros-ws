<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_billing_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscription_billing_settings', 'free_trial_days')) {
                $table->unsignedSmallInteger('free_trial_days')->default(30)->after('two_year_discount_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_billing_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('subscription_billing_settings', 'free_trial_days')) {
                $table->dropColumn('free_trial_days');
            }
        });
    }
};
