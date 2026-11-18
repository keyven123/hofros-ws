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
        Schema::create('partner_portal_integrations', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('portal_key', 40)->unique();
            $table->string('name', 120);
            $table->string('api_base_url', 255)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['portal_key' => 'ticketoc', 'name' => 'Ticketoc', 'api_base_url' => env('TICKETOC_API_BASE_URL', 'http://127.0.0.1:8000/api/v1')],
            ['portal_key' => 'booking_com', 'name' => 'Booking.com', 'api_base_url' => null],
            ['portal_key' => 'airbnb', 'name' => 'Airbnb', 'api_base_url' => null],
            ['portal_key' => 'agoda', 'name' => 'Agoda', 'api_base_url' => null],
            ['portal_key' => 'tripadvisor', 'name' => 'Trip.com', 'api_base_url' => null],
        ];

        foreach ($defaults as $row) {
            DB::table('partner_portal_integrations')->insert([
                'uuid' => (string) Str::uuid(),
                'portal_key' => $row['portal_key'],
                'name' => $row['name'],
                'api_base_url' => $row['api_base_url'],
                'is_enabled' => true,
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_portal_integrations');
    }
};
