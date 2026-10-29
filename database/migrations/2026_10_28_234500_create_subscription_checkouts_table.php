<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_checkouts', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid');
            $table->string('checkout_session_id')->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 8)->default('PHP');
            $table->date('due_on');
            $table->string('status', 24)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_checkouts');
    }
};
