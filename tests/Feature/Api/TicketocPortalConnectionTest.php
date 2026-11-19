<?php

namespace Tests\Feature\Api;

use App\Http\Repositories\BookingPortalRepository;
use App\Models\BookingPortalConnection;
use App\Models\PartnerPortalIntegration;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Facades\Http;

class TicketocPortalConnectionTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testTicketocConnectRequiresCredentials(): void
    {
        $this->authenticate();

        $this->postJson('/api/v1/booking-portals/ticketoc/connect', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testTicketocChannelOffersDedicatedConnectAction(): void
    {
        $this->authenticate();

        $this->getJson('/api/v1/booking-portals')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'ticketoc',
                'disconnectedActionKind' => 'open_ticketoc_connect',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testTicketocSettingsAndConnectFlow(): void
    {
        $this->authenticate();
        config([
            'services.ticketoc.base_url' => 'https://ticketoc.test/api/v1',
            'services.ticketoc.ota_partner_key' => 'tkoc_test_partner_key_1234567890',
        ]);
        PartnerPortalIntegration::forPortal('ticketoc')?->update([
            'api_base_url' => 'https://ticketoc.test/api/v1',
            'is_enabled' => true,
        ]);

        Http::fake([
            'https://ticketoc.test/api/v1/admin/login' => Http::response([
                'access_token' => 'tok_test_123',
                'token_type' => 'Bearer',
                'expires_in' => now()->addHour()->toDateTimeString(),
                'admin_user' => [
                    'uuid' => 'admin-1',
                    'email' => 'merchant@ticketoc.test',
                    'organization_uuid' => 'org-1',
                    'organization' => [
                        'uuid' => 'org-1',
                        'name' => 'Ticketoc Merchant',
                    ],
                ],
            ]),
            'https://ticketoc.test/api/v1/admin/me' => Http::response([
                'uuid' => 'admin-1',
                'organization_uuid' => 'org-1',
            ]),
            'https://ticketoc.test/api/v1/stays/stats' => Http::response([
                'data' => ['total' => 3],
            ]),
            'https://ticketoc.test/api/v1/stays*' => Http::response([
                'data' => [],
                'meta' => ['total' => 3],
            ]),
            'https://ticketoc.test/api/v1/integrations/stay/inventory' => Http::response([
                'ok' => true,
                'result' => ['properties' => 1, 'rooms' => 1, 'blocks' => 0],
            ]),
        ]);

        $property = Property::factory()->create([
            'user_uuid' => $this->authUser->uuid,
            'property_name' => 'Harbor House',
        ]);
        Unit::factory()->create([
            'user_uuid' => $this->authUser->uuid,
            'property_uuid' => $property->uuid,
            'name' => 'Deluxe Room',
        ]);

        $this->getJson('/api/v1/booking-portals/ticketoc/settings')
            ->assertOk()
            ->assertJsonPath('settings.isConnected', false)
            ->assertJsonPath('settings.apiConfigured', true)
            ->assertJsonPath('channel.disconnectedActionKind', 'open_ticketoc_connect');

        $this->postJson('/api/v1/booking-portals/ticketoc/test', [
            'email' => 'merchant@ticketoc.test',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('result.ok', true)
            ->assertJsonPath('result.listingCount', 1);

        $this->postJson('/api/v1/booking-portals/ticketoc/connect', [
            'email' => 'merchant@ticketoc.test',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('settings.isConnected', true)
            ->assertJsonPath('settings.organizationName', 'Ticketoc Merchant')
            ->assertJsonPath('settings.listingCount', 1)
            ->assertJsonPath('channel.isConnected', true);

        $this->assertDatabaseHas('booking_portal_connections', [
            'user_uuid' => $this->authUser->uuid,
            'portal_key' => BookingPortalRepository::TICKETOC_KEY,
            'is_connected' => 1,
            'listing_count' => 1,
            'external_organization_uuid' => 'org-1',
        ]);

        $this->postJson('/api/v1/booking-portals/'.BookingPortalRepository::TICKETOC_KEY.'/sync')
            ->assertOk()
            ->assertJsonPath('channel.isConnected', true);

        $this->postJson('/api/v1/booking-portals/ticketoc/disconnect')
            ->assertOk()
            ->assertJsonPath('settings.isConnected', false);

        $row = BookingPortalConnection::query()
            ->where('user_uuid', $this->authUser->uuid)
            ->where('portal_key', BookingPortalRepository::TICKETOC_KEY)
            ->first();

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_connected);
        $this->assertNull($row->external_api_secret);
    }
}
