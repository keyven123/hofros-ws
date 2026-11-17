<?php

namespace Tests\Feature\Api;

use App\Models\Property;
use App\Models\Unit;
use App\Models\User;

class GettingStartedTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testNewMerchantSeesIncompleteGuideAndCanDismiss(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $this->withToken($merchant->createToken('merchant')->plainTextToken);

        $this->getJson('/api/v1/getting-started')
            ->assertOk()
            ->assertJsonPath('dismissed', false)
            ->assertJsonPath('completed', false)
            ->assertJsonPath('remaining', 4)
            ->assertJsonPath('steps.0.id', 'accommodation')
            ->assertJsonPath('steps.0.done', false);

        $this->postJson('/api/v1/getting-started/dismiss')
            ->assertOk()
            ->assertJsonPath('dismissed', true)
            ->assertJsonPath('remaining', 4);

        $this->assertNotNull($merchant->fresh()->getting_started_dismissed_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testGuideMarksAccommodationAndRoomsComplete(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $property = Property::factory()->create(['user_uuid' => $merchant->uuid]);
        Unit::factory()->create([
            'user_uuid' => $merchant->uuid,
            'property_uuid' => $property->uuid,
            'price_per_night' => 1500,
        ]);

        $this->withToken($merchant->createToken('merchant')->plainTextToken);

        $this->getJson('/api/v1/getting-started')
            ->assertOk()
            ->assertJsonPath('steps.0.done', true)
            ->assertJsonPath('steps.1.done', true)
            ->assertJsonPath('steps.2.done', true)
            ->assertJsonPath('steps.3.done', false)
            ->assertJsonPath('remaining', 1);
    }
}
