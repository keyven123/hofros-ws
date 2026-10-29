<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('full_name')->default('');
            $table->string('address')->default('');
            $table->string('contact_number', 30)->default('');
        });

        DB::table('customers')->orderBy('uuid')->lazy()->each(function (object $row): void {
            DB::table('customers')->where('uuid', $row->uuid)->update([
                'full_name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->dropColumn(['full_name', 'address', 'contact_number']);
        });
    }
};
