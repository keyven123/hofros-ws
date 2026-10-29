<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payment_settings', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->unique();
            $table->boolean('accept_cash')->default(true);
            $table->boolean('accept_gcash')->default(false);
            $table->boolean('accept_bank_transfer')->default(false);
            $table->boolean('accept_card')->default(false);
            $table->string('gcash_name')->nullable();
            $table->string('gcash_number', 30)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('currency', 8)->default('PHP');
            $table->boolean('deposit_required')->default(false);
            $table->unsignedTinyInteger('deposit_percent')->default(0);
            $table->text('instructions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payment_settings');
    }
};
