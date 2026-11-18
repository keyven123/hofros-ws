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
            $table->dropUnique(['user_uuid', 'promotion_type']);
            $table->index(['user_uuid', 'promotion_type']);
        });

        // Remove empty auto-seeded placeholders so merchants start from Create Promotion.
        DB::table('promotions')
            ->where('is_enabled', false)
            ->where('discount_value', 0)
            ->whereNull('start_date')
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropIndex(['user_uuid', 'promotion_type']);
            $table->unique(['user_uuid', 'promotion_type']);
        });
    }
};
