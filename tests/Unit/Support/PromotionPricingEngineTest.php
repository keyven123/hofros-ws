<?php

namespace Tests\Unit\Support;

use App\Models\Booking;
use App\Models\Promotion;
use App\Models\User;
use App\Support\PromotionPricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PromotionPricingEngineTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function testEarlyBirdRequiresAdvanceDaysAndPicksHighestDiscount(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);

        Promotion::query()->create([
            'user_uuid' => $merchant->uuid,
            'promotion_type' => Promotion::TYPE_EARLY_BIRD,
            'is_enabled' => true,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'no_end_date' => true,
            'eligibility_days' => 30,
            'minimum_nights' => null,
            'discount_type' => Promotion::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
        ]);

        Promotion::query()->create([
            'user_uuid' => $merchant->uuid,
            'promotion_type' => Promotion::TYPE_WEEKLY_RATE,
            'is_enabled' => true,
            'start_date' => null,
            'end_date' => null,
            'no_end_date' => true,
            'eligibility_days' => null,
            'minimum_nights' => 7,
            'discount_type' => Promotion::DISCOUNT_PERCENTAGE,
            'discount_value' => 15,
        ]);

        $result = PromotionPricingEngine::apply(
            $merchant->uuid,
            10000,
            7,
            Carbon::parse('2026-12-30'),
            ['bookingDate' => '2026-11-20', 'bookingSource' => Booking::SOURCE_DIRECT_PORTAL],
        );

        $this->assertSame(Promotion::TYPE_WEEKLY_RATE, $result['promotionType']);
        $this->assertSame(1500.0, $result['discountAmount']);
        $this->assertSame(8500.0, $result['finalAmount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testLastMinuteAndMobileChannelRules(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);

        Promotion::query()->create([
            'user_uuid' => $merchant->uuid,
            'promotion_type' => Promotion::TYPE_LAST_MINUTE,
            'is_enabled' => true,
            'start_date' => '2026-01-01',
            'no_end_date' => true,
            'eligibility_days' => 3,
            'discount_type' => Promotion::DISCOUNT_FIXED,
            'discount_value' => 300,
        ]);

        Promotion::query()->create([
            'user_uuid' => $merchant->uuid,
            'promotion_type' => Promotion::TYPE_MOBILE_APP,
            'is_enabled' => true,
            'start_date' => '2026-01-01',
            'no_end_date' => true,
            'discount_type' => Promotion::DISCOUNT_PERCENTAGE,
            'discount_value' => 20,
        ]);

        $web = PromotionPricingEngine::apply(
            $merchant->uuid,
            3000,
            2,
            Carbon::parse('2026-12-30'),
            ['bookingDate' => '2026-12-28', 'bookingSource' => Booking::SOURCE_DIRECT_PORTAL],
        );
        $this->assertSame(Promotion::TYPE_LAST_MINUTE, $web['promotionType']);
        $this->assertSame(300.0, $web['discountAmount']);

        $mobile = PromotionPricingEngine::apply(
            $merchant->uuid,
            3000,
            2,
            Carbon::parse('2026-12-30'),
            ['bookingDate' => '2026-12-28', 'bookingSource' => Booking::SOURCE_MOBILE_APP],
        );
        $this->assertSame(Promotion::TYPE_MOBILE_APP, $mobile['promotionType']);
        $this->assertSame(600.0, $mobile['discountAmount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDisabledAndExpiredPromotionsNeverApply(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);

        Promotion::query()->create([
            'user_uuid' => $merchant->uuid,
            'promotion_type' => Promotion::TYPE_EARLY_BIRD,
            'is_enabled' => false,
            'start_date' => '2026-01-01',
            'no_end_date' => true,
            'eligibility_days' => 10,
            'discount_type' => Promotion::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
        ]);

        $result = PromotionPricingEngine::apply(
            $merchant->uuid,
            5000,
            2,
            Carbon::parse('2026-12-30'),
            ['bookingDate' => '2026-11-01'],
        );

        $this->assertNull($result['promotion']);
        $this->assertSame(0.0, $result['discountAmount']);
        $this->assertSame(5000.0, $result['finalAmount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testWeeklyFixedAmountIsPerNight(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);

        Promotion::query()->create([
            'user_uuid' => $merchant->uuid,
            'promotion_type' => Promotion::TYPE_WEEKLY_RATE,
            'is_enabled' => true,
            'no_end_date' => true,
            'minimum_nights' => 7,
            'discount_type' => Promotion::DISCOUNT_FIXED,
            'discount_value' => 200,
        ]);

        $result = PromotionPricingEngine::apply(
            $merchant->uuid,
            10000,
            7,
            Carbon::parse('2026-12-30'),
            ['bookingDate' => '2026-12-01'],
        );

        $this->assertSame(1400.0, $result['discountAmount']);
        $this->assertSame(8600.0, $result['finalAmount']);
    }
}
