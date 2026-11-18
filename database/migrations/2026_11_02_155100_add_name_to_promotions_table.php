<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->string('name', 120)->nullable()->after('promotion_type');
        });

        $defaults = [
            'early_bird' => 'Early Bird Promotion',
            'last_minute' => 'Last Minute Promotion',
            'weekly_rate' => 'Weekly Rate',
            'monthly_rate' => 'Monthly Rate',
            'mobile_app' => 'Hofros Mobile App Discount',
        ];

        foreach ($defaults as $type => $name) {
            DB::table('promotions')->where('promotion_type', $type)->whereNull('name')->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
