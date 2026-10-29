<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_prospect_notes', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('prospect_uuid');
            $table->text('body');
            $table->timestamps();
            $table->index('prospect_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_prospect_notes');
    }
};
