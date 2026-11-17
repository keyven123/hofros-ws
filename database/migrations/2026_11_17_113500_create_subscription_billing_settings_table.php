<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_billing_settings', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->unsignedTinyInteger('yearly_discount_percent')->default(66);
            $table->unsignedTinyInteger('two_year_discount_percent')->default(70);
            $table->timestamps();
        });

        $now = now();
        DB::table('subscription_billing_settings')->insert([
            'uuid' => (string) Str::uuid(),
            'yearly_discount_percent' => 66,
            'two_year_discount_percent' => 70,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_billing_settings');
    }
};
