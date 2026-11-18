<?php

namespace Tests\Feature\Api;

use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCanCreateUpdateToggleAndDeletePromotions(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        Sanctum::actingAs($merchant);

        $this->getJson('/api/v1/discounts/promotions')
            ->assertOk()
            ->assertJsonCount(0, 'promotions')
            ->assertJsonCount(5, 'promotionTypes');

        $created = $this->postJson('/api/v1/discounts/promotions', [
            'name' => 'Summer Early Bird',
            'promotionType' => 'early_bird',
            'isEnabled' => true,
            'startDate' => '2026-01-01',
            'noEndDate' => true,
            'eligibilityDays' => 30,
            'discountType' => 'percentage',
            'discountValue' => 15,
        ])
            ->assertCreated()
            ->assertJsonPath('promotion.name', 'Summer Early Bird')
            ->assertJsonPath('promotion.isEnabled', true)
            ->assertJsonPath('promotion.discountValue', 15);

        $id = $created->json('promotion.id');

        $this->putJson('/api/v1/discounts/promotions/'.$id, [
            'name' => 'Holiday Early Bird',
            'isEnabled' => true,
            'startDate' => '2026-01-01',
            'noEndDate' => true,
            'eligibilityDays' => 45,
            'discountType' => 'percentage',
            'discountValue' => 20,
        ])
            ->assertOk()
            ->assertJsonPath('promotion.name', 'Holiday Early Bird')
            ->assertJsonPath('promotion.eligibilityDays', 45)
            ->assertJsonPath('promotion.discountValue', 20);

        $this->postJson('/api/v1/discounts/promotions/'.$id.'/toggle')
            ->assertOk()
            ->assertJsonPath('promotion.isEnabled', false);

        $this->deleteJson('/api/v1/discounts/promotions/'.$id)
            ->assertOk();

        $this->assertSoftDeleted('promotions', [
            'uuid' => $id,
            'user_uuid' => $merchant->uuid,
            'promotion_type' => Promotion::TYPE_EARLY_BIRD,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testValidationRejectsInvalidPercentageAndMissingEligibility(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        Sanctum::actingAs($merchant);

        $this->postJson('/api/v1/discounts/promotions', [
            'name' => 'Bad Promo',
            'promotionType' => 'early_bird',
            'isEnabled' => true,
            'startDate' => '2026-01-01',
            'noEndDate' => true,
            'eligibilityDays' => 0,
            'discountType' => 'percentage',
            'discountValue' => 150,
        ])->assertStatus(422);
    }
}
