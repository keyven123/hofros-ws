<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('guest_messages', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('merchant_user_uuid')->constrained('users', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('customer_uuid')->constrained('customers', 'uuid')->cascadeOnDelete();
            $table->string('sender', 16);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_user_uuid', 'customer_uuid', 'created_at'], 'guest_messages_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_messages');
        Schema::dropIfExists('customers');
    }
};
