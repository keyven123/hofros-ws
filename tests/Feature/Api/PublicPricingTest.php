<?php

namespace Tests\Feature\Api;

use App\Support\SubscriptionPlanCatalog;

class PublicPricingTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testPublicPricingReturnsRoomBandsAndBillingCycles(): void
    {
        $response = $this->getJson('/api/v1/public/pricing')->assertOk();

        $bands = collect($response->json('bands'));
        $this->assertGreaterThanOrEqual(19, $bands->count());
        $this->assertSame(850.0, (float) $bands->firstWhere('maxRooms', 4)['pricePerRoom']);
        $this->assertSame(675.0, (float) $bands->firstWhere('maxRooms', 10)['pricePerRoom']);
        $this->assertSame(499.0, (float) $response->json('uncapped.pricePerRoom'));
        $this->assertCount(3, $response->json('billingCycles'));
        $this->assertSame(66, $response->json('billingCycles.1.discountPercent'));
        $this->assertSame(70, $response->json('billingCycles.2.discountPercent'));
        $this->assertCount(count(SubscriptionPlanCatalog::baselines()) - 1, $bands);
    }
}
