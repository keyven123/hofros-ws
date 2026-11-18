<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->uuid('uuid')->primary()->unique()->index();
            $table->uuid('user_uuid')->index();
            $table->string('promotion_type', 32)->index();
            $table->boolean('is_enabled')->default(false)->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('no_end_date')->default(false);
            $table->unsignedInteger('eligibility_days')->nullable();
            $table->unsignedInteger('minimum_nights')->nullable();
            $table->string('discount_type', 32)->default('percentage');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_uuid', 'promotion_type']);
            $table->index(['user_uuid', 'is_enabled']);
            $table->index(['created_at', 'updated_at'], 'promotions_timestamp_index');
        });

        Schema::create('booking_promotions', function (Blueprint $table): void {
            $table->uuid('uuid')->primary()->unique()->index();
            $table->uuid('booking_uuid')->index();
            $table->uuid('promotion_uuid')->nullable()->index();
            $table->string('promotion_type', 32)->index();
            $table->string('promotion_name', 120);
            $table->decimal('original_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('final_amount', 12, 2);
            $table->string('discount_type', 32);
            $table->decimal('discount_value', 12, 2);
            $table->string('selection_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['created_at', 'updated_at'], 'booking_promotions_timestamp_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_promotions');
        Schema::dropIfExists('promotions');
    }
};
