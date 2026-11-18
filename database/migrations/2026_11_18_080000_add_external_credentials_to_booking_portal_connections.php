<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_portal_connections', function (Blueprint $table): void {
            $table->text('external_credentials')->nullable()->after('external_api_secret');
        });
    }

    public function down(): void
    {
        Schema::table('booking_portal_connections', function (Blueprint $table): void {
            $table->dropColumn('external_credentials');
        });
    }
};
