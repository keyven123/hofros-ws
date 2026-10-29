<?php

namespace Tests\Feature\Api;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;

class AdminConsoleTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testPlatformAdminCanReadMerchantsBookingsAndRooms(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@hofros.com',
            'is_platform_admin' => true,
            'merchant_name' => 'Platform Admin',
        ]);
        $merchant = User::factory()->create([
            'merchant_name' => 'Vinz Hotels',
            'is_platform_admin' => false,
        ]);
        $unit = Unit::factory()->create([
            'user_uuid' => $merchant->uuid,
            'name' => 'Garden Suite',
        ]);
        Booking::factory()->create([
            'user_uuid' => $merchant->uuid,
            'unit_uuid' => $unit->uuid,
            'guest_name' => 'Arnold Borak',
            'check_in' => '2026-11-06',
            'check_out' => '2026-11-07',
            'status' => Booking::STATUS_ASSIGNED,
        ]);

        $this->authenticate($admin);

        $this->getJson('/api/v1/admin/merchants')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Vinz Hotels'])
            ->assertJsonMissing(['email' => 'admin@hofros.com']);

        $this->getJson('/api/v1/admin/bookings')
            ->assertOk()
            ->assertJsonPath('bookings.0.guest', 'Arnold Borak')
            ->assertJsonPath('bookings.0.merchant', 'Vinz Hotels')
            ->assertJsonPath('bookings.0.room', 'Garden Suite');

        $this->getJson('/api/v1/admin/calendars?from=2026-11-01&to=2026-11-16')
            ->assertOk()
            ->assertJsonPath('from', '2026-11-01')
            ->assertJsonPath('to', '2026-11-16')
            ->assertJsonFragment(['name' => 'Garden Suite'])
            ->assertJsonFragment(['guest' => 'Arnold Borak']);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.merchants', 2)
            ->assertJsonPath('counts.rooms', 1)
            ->assertJsonPath('counts.bookings', 1)
            ->assertJsonCount(6, 'bookingsByMonth')
            ->assertJsonCount(6, 'statusBreakdown');

        $this->getJson('/api/v1/admin/merchants/'.$merchant->uuid)
            ->assertOk()
            ->assertJsonPath('merchant.name', 'Vinz Hotels')
            ->assertJsonPath('rooms.0.name', 'Garden Suite')
            ->assertJsonPath('bookings.0.guest', 'Arnold Borak')
            ->assertJsonPath('counts.rooms', 1)
            ->assertJsonPath('counts.bookings', 1);

        $this->getJson('/api/v1/admin/merchants/'.$admin->uuid)
            ->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCannotOpenTheAdminConsole(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $token = $merchant->createToken('merchant')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }
}
