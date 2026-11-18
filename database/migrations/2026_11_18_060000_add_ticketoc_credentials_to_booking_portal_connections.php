<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_portal_connections', function (Blueprint $table): void {
            $table->string('external_base_url')->nullable()->after('guest_portal_layout');
            $table->string('external_merchant_email')->nullable()->after('external_base_url');
            $table->text('external_api_secret')->nullable()->after('external_merchant_email');
            $table->text('external_access_token')->nullable()->after('external_api_secret');
            $table->timestamp('external_token_expires_at')->nullable()->after('external_access_token');
            $table->uuid('external_organization_uuid')->nullable()->index()->after('external_token_expires_at');
            $table->string('external_organization_name')->nullable()->after('external_organization_uuid');
            $table->json('connection_meta')->nullable()->after('external_organization_name');
        });
    }

    public function down(): void
    {
        Schema::table('booking_portal_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'external_base_url',
                'external_merchant_email',
                'external_api_secret',
                'external_access_token',
                'external_token_expires_at',
                'external_organization_uuid',
                'external_organization_name',
                'connection_meta',
            ]);
        });
    }
};
