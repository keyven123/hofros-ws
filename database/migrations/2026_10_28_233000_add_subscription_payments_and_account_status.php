<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'account_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('account_status', 24)->default('active')->after('is_platform_admin');
            });
        }

        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 8)->default('PHP');
            $table->date('due_on');
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['user_uuid', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('account_status');
        });
    }
};
