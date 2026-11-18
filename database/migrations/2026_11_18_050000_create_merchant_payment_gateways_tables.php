<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payment_gateways', function (Blueprint $table): void {
            $table->uuid('uuid')->primary()->unique()->index();
            $table->uuid('user_uuid')->index();
            $table->string('provider', 32)->index();
            $table->string('name', 120);
            $table->string('environment', 32)->default('testing')->index();
            $table->text('public_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('api_username')->nullable();
            $table->text('api_password')->nullable();
            $table->string('status', 32)->default('inactive')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_uuid', 'status']);
            $table->index(['user_uuid', 'provider']);
            $table->index(['created_at', 'updated_at'], 'merchant_payment_gateways_timestamp_index');
        });

        Schema::create('booking_checkouts', function (Blueprint $table): void {
            $table->uuid('uuid')->primary()->unique()->index();
            $table->uuid('user_uuid')->index();
            $table->uuid('payment_gateway_uuid')->nullable()->index();
            $table->string('provider', 32)->index();
            $table->uuid('portal_batch_id')->nullable()->index();
            $table->uuid('booking_uuid')->nullable()->index();
            $table->string('reference', 64)->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('PHP');
            $table->string('status', 32)->default('pending')->index();
            $table->string('provider_session_id')->nullable()->index();
            $table->text('checkout_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'updated_at'], 'booking_checkouts_timestamp_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_checkouts');
        Schema::dropIfExists('merchant_payment_gateways');
    }
};
