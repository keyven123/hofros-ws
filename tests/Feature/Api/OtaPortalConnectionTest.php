<?php

namespace Tests\Feature\Api;

use App\Models\BookingPortalConnection;
use App\Models\PartnerPortalIntegration;
use Illuminate\Support\Facades\Http;

class OtaPortalConnectionTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testOtaChannelsOfferDedicatedConnectAction(): void
    {
        $this->authenticate();

        $this->getJson('/api/v1/booking-portals')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'booking_com',
                'disconnectedActionKind' => 'open_ota_connect',
            ])
            ->assertJsonFragment([
                'id' => 'airbnb',
                'disconnectedActionKind' => 'open_airbnb_connect',
            ])
            ->assertJsonFragment([
                'id' => 'agoda',
                'disconnectedActionKind' => 'open_ota_connect',
            ])
            ->assertJsonFragment([
                'id' => 'tripadvisor',
                'disconnectedActionKind' => 'open_ota_connect',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testBookingComConnectRequiresFieldsAndSavesCredentials(): void
    {
        $this->authenticate();
        PartnerPortalIntegration::forPortal('booking_com')?->update([
            'api_base_url' => 'https://booking.partners.test',
            'is_enabled' => true,
        ]);

        $this->getJson('/api/v1/booking-portals/booking_com/ota/settings')
            ->assertOk()
            ->assertJsonPath('settings.portalKey', 'booking_com')
            ->assertJsonPath('settings.apiConfigured', true)
            ->assertJsonStructure(['settings' => ['fields', 'values']]);

        $this->postJson('/api/v1/booking-portals/booking_com/ota/connect', [
            'hotelId' => '998877',
            'clientId' => 'client-abc',
            'clientSecret' => 'secret-xyz-123',
        ])
            ->assertOk()
            ->assertJsonPath('settings.isConnected', true)
            ->assertJsonPath('settings.propertyId', '998877')
            ->assertJsonPath('channel.isConnected', true);

        $row = BookingPortalConnection::query()
            ->where('user_uuid', $this->authUser->uuid)
            ->where('portal_key', 'booking_com')
            ->first();

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->is_connected);
        $this->assertSame('998877', $row->external_organization_uuid);
        $this->assertSame('secret-xyz-123', $row->external_credentials['clientSecret'] ?? null);

        $this->postJson('/api/v1/booking-portals/booking_com/ota/disconnect')
            ->assertOk()
            ->assertJsonPath('settings.isConnected', false);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testGenericConnectIsBlockedForOtaPortals(): void
    {
        $this->authenticate();

        $this->postJson('/api/v1/booking-portals/airbnb/connect', [])
            ->assertStatus(422);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAirbnbConnectsWithUserIdOnlyAndCapturesAccommodations(): void
    {
        $this->authenticate();
        PartnerPortalIntegration::forPortal('airbnb')?->update([
            'api_base_url' => 'https://www.airbnb.com',
            'is_enabled' => true,
        ]);

        $this->fakeAirbnbHttp([
            '751144161' => [
                [
                    'id' => '1728707002897833435',
                    'nameOrPlaceholderName' => 'Homefort Stays @ S Residences 2BR Suite Near MOA',
                    'propertyTypeName' => 'Apartment',
                    'localizedRoomType' => 'Entire home/apt',
                ],
                [
                    'id' => '1640299830525198328',
                    'nameOrPlaceholderName' => 'Homefort Twin Bed · Lush Residences Unit 1136',
                    'propertyTypeName' => 'Apartment',
                    'localizedRoomType' => 'Entire home/apt',
                ],
            ],
            '999888777' => [
                [
                    'id' => '5555555555555555555',
                    'nameOrPlaceholderName' => 'Second Host Studio Downtown',
                    'propertyTypeName' => 'Condo',
                    'localizedRoomType' => 'Entire home/apt',
                ],
            ],
        ]);

        $this->getJson('/api/v1/booking-portals/airbnb/ota/settings')
            ->assertOk()
            ->assertJsonPath('settings.fields.0.key', 'userId')
            ->assertJsonCount(1, 'settings.fields');

        $this->postJson('/api/v1/booking-portals/airbnb/ota/connect', [
            'userId' => '751144161',
        ])
            ->assertOk()
            ->assertJsonPath('settings.isConnected', true)
            ->assertJsonPath('settings.propertyId', '751144161')
            ->assertJsonPath('settings.listingCount', 2)
            ->assertJsonPath('settings.hostCount', 1);

        $this->getJson('/api/v1/booking-portals/airbnb/accommodations')
            ->assertOk()
            ->assertJsonPath('userId', '751144161')
            ->assertJsonPath('listingCount', 2)
            ->assertJsonPath('hostCount', 1)
            ->assertJsonPath('hosts.0.userId', '751144161')
            ->assertJsonPath('hosts.0.isActive', true)
            ->assertJsonPath('listings.0.title', 'Homefort Stays @ S Residences 2BR Suite Near MOA');

        $this->postJson('/api/v1/booking-portals/airbnb/hosts', [
            'userId' => '999888777',
        ])
            ->assertOk()
            ->assertJsonPath('hostCount', 2)
            ->assertJsonPath('userId', '999888777')
            ->assertJsonPath('listingCount', 1)
            ->assertJsonPath('totalListingCount', 3);

        $this->patchJson('/api/v1/booking-portals/airbnb/hosts/999888777', [
            'isActive' => false,
        ])
            ->assertOk()
            ->assertJsonPath('hosts.1.isActive', false)
            ->assertJsonPath('totalListingCount', 2);

        $this->getJson('/api/v1/booking-portals/airbnb/accommodations?userId=751144161')
            ->assertOk()
            ->assertJsonPath('userId', '751144161')
            ->assertJsonPath('isActive', true);

        $this->postJson('/api/v1/booking-portals/airbnb/accommodations/reload', [
            'userId' => '751144161',
        ])
            ->assertOk()
            ->assertJsonPath('listingCount', 2);

        $this->deleteJson('/api/v1/booking-portals/airbnb/hosts/999888777')
            ->assertOk()
            ->assertJsonPath('hostCount', 1)
            ->assertJsonPath('userId', '751144161');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $listingsByUserId
     */
    private function fakeAirbnbHttp(array $listingsByUserId): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($listingsByUserId) {
            $url = $request->url();

            if (str_contains($url, '/api/v3/UserProfileBeehiveListingQuery/')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $variables = json_decode((string) ($query['variables'] ?? '{}'), true);
                $userId = (string) ($variables['userId'] ?? '');
                $listings = $listingsByUserId[$userId] ?? [];

                return Http::response([
                    'data' => [
                        'beehive' => [
                            'getListOfListings' => [
                                'listings' => $listings,
                            ],
                        ],
                    ],
                ], 200);
            }

            if (preg_match('#^https://www\.airbnb\.com/?(\?.*)?$#', $url) === 1) {
                return Http::response(
                    '<html><script>window.__BOOT={"api_config":{"key":"test_airbnb_api_key","baseUrl":"/api"}}</script></html>',
                    200
                );
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });
    }
}
