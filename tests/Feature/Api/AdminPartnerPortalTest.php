<?php

namespace Tests\Feature\Api;

use App\Models\PartnerPortalIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class AdminPartnerPortalTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanListAndUpdatePartnerPortalApis(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/partner-portals')
            ->assertOk()
            ->assertJsonPath('integrations.0.portalKey', 'ticketoc')
            ->assertJsonStructure([
                'integrations' => [
                    ['portalKey', 'name', 'apiBaseUrl', 'isEnabled', 'isConfigured'],
                ],
            ]);

        $this->putJson('/api/v1/admin/partner-portals', [
            'integrations' => [
                [
                    'portalKey' => 'ticketoc',
                    'apiBaseUrl' => 'https://ticketoc.partners.test/api/v1',
                    'isEnabled' => true,
                ],
                [
                    'portalKey' => 'booking_com',
                    'apiBaseUrl' => 'https://booking.partners.test',
                    'isEnabled' => false,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('integrations.0.apiBaseUrl', 'https://ticketoc.partners.test/api/v1')
            ->assertJsonPath('integrations.1.isEnabled', false);

        $this->assertSame(
            'https://ticketoc.partners.test/api/v1',
            PartnerPortalIntegration::resolveBaseUrl('ticketoc')
        );
        $this->assertFalse(PartnerPortalIntegration::isEnabled('booking_com'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanSaveTicketocStayPartnerKeyAndConnect(): void
    {
        config(['services.ticketoc.ota_partner_key' => null]);
        $key = 'tkoc_admin_partner_key_value';
        $admin = User::factory()->create(['is_platform_admin' => true]);
        Sanctum::actingAs($admin);

        $saved = $this->putJson('/api/v1/admin/partner-portals', [
            'integrations' => [
                [
                    'portalKey' => 'ticketoc',
                    'apiBaseUrl' => 'https://ticketoc.partners.test/api/v1',
                    'isEnabled' => true,
                    'stayPartnerKey' => $key,
                ],
            ],
        ])->assertOk();

        $saved->assertJsonPath('integrations.0.stayPartnerKeySet', true);
        $saved->assertJsonPath('integrations.0.stayStatus', 'saved');
        $saved->assertJsonPath('integrations.0.stayPartnerKeyPrefix', 'tkoc_admin_p');
        $this->assertStringNotContainsString($key, $saved->getContent());

        $this->withHeader('X-Partner-Key', $key)
            ->getJson('/api/v1/partners/ticketoc/ping')
            ->assertOk()
            ->assertJsonPath('platform', 'hofros');

        $kept = $this->putJson('/api/v1/admin/partner-portals', [
            'integrations' => [
                [
                    'portalKey' => 'ticketoc',
                    'apiBaseUrl' => 'https://ticketoc.partners.test/api/v1',
                    'isEnabled' => true,
                ],
            ],
        ])->assertOk();
        $kept->assertJsonPath('integrations.0.stayPartnerKeySet', true);
        $this->assertSame($key, PartnerPortalIntegration::forPortal('ticketoc')?->storedOtaPartnerKey());

        Http::fake([
            'https://ticketoc.partners.test/api/v1/integrations/stay/ping' => Http::sequence()
                ->push(['ok' => true, 'platform' => 'ticketoc'], 200)
                ->push(['message' => 'This Stay integration is disconnected.'], 409)
                ->push(['message' => 'Unauthorized.'], 401),
        ]);

        $this->postJson('/api/v1/admin/partner-portals/ticketoc/stay/connect', [
            'apiBaseUrl' => 'https://ticketoc.partners.test/api/v1',
        ])
            ->assertOk()
            ->assertJsonPath('integration.stayStatus', 'connected')
            ->assertJsonPath('message', 'Ticketoc Stay accepted the partner key. Hofros rooms can be sold on Ticketoc.');

        $this->postJson('/api/v1/admin/partner-portals/ticketoc/stay/connect', [
            'apiBaseUrl' => 'https://ticketoc.partners.test/api/v1',
            'stayPartnerKey' => $key,
        ])
            ->assertOk()
            ->assertJsonPath('integration.stayStatus', 'ready');

        $this->postJson('/api/v1/admin/partner-portals/ticketoc/stay/connect', [
            'apiBaseUrl' => 'https://ticketoc.partners.test/api/v1',
            'stayPartnerKey' => 'tkoc_wrong_partner_key_value',
        ])
            ->assertStatus(422)
            ->assertJsonPath('integration.stayStatus', 'error');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCannotAccessPartnerPortalAdmin(): void
    {
        $this->authenticate(User::factory()->create(['is_platform_admin' => false]));

        $this->getJson('/api/v1/admin/partner-portals')->assertForbidden();
        $this->postJson('/api/v1/admin/partner-portals/ticketoc/stay/connect', [])->assertForbidden();
    }
}
