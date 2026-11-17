<?php

namespace Tests\Feature\Api;

use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Support\SubscriptionPlanCatalog;

class AdminSubscriptionTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminSeesFixedRoomBandRates(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->authenticate($admin);

        $response = $this->getJson('/api/v1/admin/subscriptions')->assertOk();
        $plans = collect($response->json('plans'));

        $this->assertCount(count(SubscriptionPlanCatalog::baselines()), $plans);
        $this->assertSame('Up to 1 room', $plans->firstWhere('maxRooms', 1)['name']);
        $this->assertSame(850.0, (float) $plans->firstWhere('maxRooms', 4)['pricePerRoom']);
        $this->assertSame(675.0, (float) $plans->firstWhere('maxRooms', 10)['pricePerRoom']);
        $this->assertSame(499.0, (float) $plans->firstWhere('maxRooms', null)['pricePerRoom']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanCreateAPlanAndSubscribeAMerchantPerRoom(): void
    {
        SubscriptionFeature::query()->update(['monthly_price' => 0]);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $merchant = User::factory()->create([
            'merchant_name' => 'Vinz Hotels',
            'is_platform_admin' => false,
        ]);
        Unit::factory()->count(3)->create(['user_uuid' => $merchant->uuid]);

        $this->authenticate($admin);

        $oneRoom = collect($this->getJson('/api/v1/admin/subscriptions')->json('plans'))
            ->firstWhere('maxRooms', 1);

        $this->postJson('/api/v1/admin/subscriptions/assign', [
            'merchant_id' => $merchant->uuid,
            'plan_id' => $oneRoom['id'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Up to 1 room allows 1 rooms. Vinz Hotels has 3 rooms.');

        $this->postJson('/api/v1/admin/subscriptions/plans', [
            'name' => 'House',
            'description' => 'A custom plan.',
            'price_per_room' => 200,
            'max_rooms' => 25,
            'modules' => ['dashboard', 'bookings'],
        ])
            ->assertCreated()
            ->assertJsonFragment([
                'name' => 'House',
                'pricePerRoom' => 200,
                'maxRooms' => 25,
            ]);

        $threeRooms = collect($this->getJson('/api/v1/admin/subscriptions')->json('plans'))
            ->firstWhere('maxRooms', 3);

        $this->postJson('/api/v1/admin/subscriptions/assign', [
            'merchant_id' => $merchant->uuid,
            'plan_id' => $threeRooms['id'],
        ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Vinz Hotels', 'rooms' => 3])
            ->assertJsonFragment(['name' => 'Up to 3 rooms', 'monthly' => 2700]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanAddARoomBaselineWithoutModules(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->authenticate($admin);

        $this->postJson('/api/v1/admin/subscriptions/plans', [
            'price_per_room' => 899,
            'max_rooms' => 20,
        ])
            ->assertCreated()
            ->assertJsonFragment([
                'name' => 'Up to 20 rooms',
                'pricePerRoom' => 899,
                'maxRooms' => 20,
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanEditPlanSpecs(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->authenticate($admin);

        $band = SubscriptionPlan::query()->where('max_rooms', 8)->where('is_active', true)->firstOrFail();

        $this->putJson('/api/v1/admin/subscriptions/plans/'.$band->uuid, [
            'name' => 'Up to 8 rooms',
            'description' => 'Updated eight-room baseline.',
            'price_per_room' => 199,
            'max_rooms' => 8,
            'modules' => ['dashboard', 'bookings', 'messages'],
        ])
            ->assertOk()
            ->assertJsonFragment([
                'description' => 'Updated eight-room baseline.',
                'pricePerRoom' => 199,
                'maxRooms' => 8,
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanUpdateYearlyAndTwoYearDiscounts(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->authenticate($admin);

        $this->putJson('/api/v1/admin/subscriptions/billing-discounts', [
            'yearly_discount_percent' => 50,
            'two_year_discount_percent' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('billingDiscounts.yearlyDiscountPercent', 50)
            ->assertJsonPath('billingDiscounts.twoYearDiscountPercent', 60)
            ->assertJsonPath('billingCycles.1.discountPercent', 50)
            ->assertJsonPath('billingCycles.2.discountPercent', 60);

        $this->getJson('/api/v1/public/pricing')
            ->assertOk()
            ->assertJsonPath('billingCycles.1.discountPercent', 50)
            ->assertJsonPath('billingCycles.2.discountPercent', 60);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanUpdateFreeTrialDays(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->authenticate($admin);

        $this->putJson('/api/v1/admin/subscriptions/free-trial', [
            'free_trial_days' => 14,
        ])
            ->assertOk()
            ->assertJsonPath('freeTrial.days', 14);

        $this->getJson('/api/v1/public/pricing')
            ->assertOk()
            ->assertJsonPath('freeTrialDays', 14);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCannotManageSubscriptions(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $token = $merchant->createToken('merchant')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/subscriptions')
            ->assertForbidden();
    }
}
