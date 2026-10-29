<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'is_marketing')) {
                $table->boolean('is_marketing')->default(false)->after('is_platform_admin');
            }
        });

        Schema::table('marketing_prospects', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_prospects', 'owner_user_uuid')) {
                $table->uuid('owner_user_uuid')->nullable()->after('uuid');
                $table->index('owner_user_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_prospects', function (Blueprint $table): void {
            if (Schema::hasColumn('marketing_prospects', 'owner_user_uuid')) {
                $table->dropIndex(['owner_user_uuid']);
                $table->dropColumn('owner_user_uuid');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'is_marketing')) {
                $table->dropColumn('is_marketing');
            }
        });
    }
};
