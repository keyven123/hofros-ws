<?php

namespace Tests\Feature\Api;

use App\Models\PartnerPortalIntegration;
use App\Models\User;
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
    public function testMerchantCannotAccessPartnerPortalAdmin(): void
    {
        $this->authenticate(User::factory()->create(['is_platform_admin' => false]));

        $this->getJson('/api/v1/admin/partner-portals')->assertForbidden();
    }
}
