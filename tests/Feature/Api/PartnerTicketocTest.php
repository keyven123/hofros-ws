<?php

namespace Tests\Feature\Api;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitDateBlock;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class PartnerTicketocTest extends ApiTestCase
{
    private const KEY = 'tkoc_test_partner_key_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ticketoc.ota_partner_key' => self::KEY]);
    }

    #[Test]
    public function partner_api_is_hidden_until_a_key_is_configured(): void
    {
        config(['services.ticketoc.ota_partner_key' => null]);

        $this->getJson('/api/v1/partners/ticketoc/ping')->assertNotFound();
    }

    #[Test]
    public function inventory_and_bookings_require_the_partner_key(): void
    {
        $this->getJson('/api/v1/partners/ticketoc/ping')->assertUnauthorized();

        $property = Property::factory()->create(['property_name' => 'Harbor House']);
        $unit = Unit::factory()->create([
            'user_uuid' => $property->user_uuid,
            'property_uuid' => $property->uuid,
            'name' => 'Deluxe Room',
            'type' => 'Room',
            'details' => 'Queen bed, city view',
            'max_guests' => 2,
            'bedrooms' => 1,
            'beds' => 1,
            'price_per_night' => 3200,
        ]);

        $this->withHeader('X-Partner-Key', self::KEY)
            ->getJson('/api/v1/partners/ticketoc/ping')
            ->assertOk()
            ->assertJsonPath('platform', 'hofros');

        $this->withHeader('X-Partner-Key', self::KEY)
            ->getJson('/api/v1/partners/ticketoc/inventory')
            ->assertOk()
            ->assertJsonPath('properties.0.name', 'Harbor House')
            ->assertJsonPath('properties.0.units.0.name', 'Deluxe Room')
            ->assertJsonPath('properties.0.units.0.specs.details', 'Queen bed, city view')
            ->assertJsonPath('properties.0.units.0.specs.max_guests', 2);

        $otaBookingId = (string) Str::uuid();
        $this->withHeader('X-Partner-Key', self::KEY)
            ->postJson('/api/v1/partners/ticketoc/bookings', [
                'ota_booking_id' => $otaBookingId,
                'order_number' => 'STY-1001',
                'unit_id' => $unit->uuid,
                'guest_name' => 'Mina Cruz',
                'guest_email' => 'mina@example.com',
                'check_in_date' => '2026-12-10',
                'check_out_date' => '2026-12-12',
                'guest_count' => 2,
                'amount' => 6400,
                'currency' => 'PHP',
                'specs' => ['details' => 'Queen bed, city view', 'max_guests' => 2],
            ])
            ->assertCreated()
            ->assertJsonPath('created', true);

        $this->assertDatabaseHas('bookings', [
            'unit_uuid' => $unit->uuid,
            'guest_name' => 'Mina Cruz',
            'source' => Booking::SOURCE_TICKETOC,
            'status' => Booking::STATUS_ACCEPTED,
        ]);

        $this->withHeader('X-Partner-Key', self::KEY)
            ->postJson('/api/v1/partners/ticketoc/bookings', [
                'ota_booking_id' => $otaBookingId,
                'unit_id' => $unit->uuid,
                'guest_name' => 'Mina Cruz',
                'check_in_date' => '2026-12-10',
                'check_out_date' => '2026-12-12',
                'amount' => 6400,
            ])
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame(1, Booking::query()->count());
    }

    #[Test]
    public function availability_follows_the_merchant_calendar_for_the_mapped_room(): void
    {
        $property = Property::factory()->create();
        $unit = Unit::factory()->create([
            'user_uuid' => $property->user_uuid,
            'property_uuid' => $property->uuid,
            'week_schedule' => array_merge(Unit::defaultWeekSchedule(), ['wed' => false]),
        ]);
        UnitDateBlock::factory()->create([
            'user_uuid' => $property->user_uuid,
            'unit_uuid' => $unit->uuid,
            'start_date' => '2026-12-02',
            'end_date' => '2026-12-04',
            'label' => 'Owner stay',
        ]);
        Booking::factory()->create([
            'user_uuid' => $property->user_uuid,
            'unit_uuid' => $unit->uuid,
            'check_in' => '2026-12-10',
            'check_out' => '2026-12-12',
            'status' => Booking::STATUS_ACCEPTED,
        ]);
        Booking::factory()->create([
            'user_uuid' => $property->user_uuid,
            'unit_uuid' => $unit->uuid,
            'check_in' => '2026-12-05',
            'check_out' => '2026-12-06',
            'status' => Booking::STATUS_PENDING,
        ]);

        $this->withHeader('X-Partner-Key', self::KEY)
            ->getJson('/api/v1/partners/ticketoc/availability?'.http_build_query([
                'from' => '2026-12-01',
                'to' => '2026-12-14',
                'unit_ids' => [$unit->uuid],
            ]))
            ->assertOk()
            ->assertJsonPath('units.0.id', $unit->uuid)
            ->assertJsonFragment(['start_date' => '2026-12-02', 'end_date' => '2026-12-03'])
            ->assertJsonFragment(['start_date' => '2026-12-09', 'end_date' => '2026-12-11'])
            ->assertJsonMissing(['start_date' => '2026-12-05']);
    }
}
