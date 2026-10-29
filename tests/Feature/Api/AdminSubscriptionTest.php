<?php

namespace Tests\Feature\Api;

use App\Models\SubscriptionFeature;
use App\Models\Unit;
use App\Models\User;

class AdminSubscriptionTest extends ApiTestCase
{
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

        $this->getJson('/api/v1/admin/subscriptions')
            ->assertOk()
            ->assertJsonPath('plans.0.name', 'Free')
            ->assertJsonPath('plans.1.name', 'Regular')
            ->assertJsonPath('plans.2.name', 'Advance')
            ->assertJsonPath('plans.3.name', 'Enterprise');

        $free = collect($this->getJson('/api/v1/admin/subscriptions')->json('plans'))
            ->firstWhere('slug', 'free');

        $this->postJson('/api/v1/admin/subscriptions/assign', [
            'merchant_id' => $merchant->uuid,
            'plan_id' => $free['id'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Free allows 2 rooms. Vinz Hotels has 3 rooms.');

        $created = $this->postJson('/api/v1/admin/subscriptions/plans', [
            'name' => 'House',
            'description' => 'A custom plan.',
            'price_per_room' => 200,
            'max_rooms' => 5,
            'modules' => ['dashboard', 'bookings'],
        ])->assertCreated();

        $house = collect($created->json('plans'))->firstWhere('slug', 'house');

        $this->postJson('/api/v1/admin/subscriptions/assign', [
            'merchant_id' => $merchant->uuid,
            'plan_id' => $house['id'],
        ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Vinz Hotels', 'rooms' => 3])
            ->assertJsonFragment(['name' => 'House', 'monthly' => 600]);
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

        $regular = collect($this->getJson('/api/v1/admin/subscriptions')->json('plans'))
            ->firstWhere('slug', 'regular');

        $this->putJson('/api/v1/admin/subscriptions/plans/'.$regular['id'], [
            'name' => 'Regular',
            'description' => 'Updated regular plan.',
            'price_per_room' => 199,
            'max_rooms' => 12,
            'modules' => ['dashboard', 'bookings', 'messages'],
        ])
            ->assertOk()
            ->assertJsonFragment([
                'slug' => 'regular',
                'description' => 'Updated regular plan.',
                'pricePerRoom' => 199,
                'maxRooms' => 12,
            ]);
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
