<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_prospects', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('kind', 24);
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('rooms')->nullable();
            $table->string('status', 24)->default('target');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_prospects');
    }
};
