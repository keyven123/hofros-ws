<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Promotion;
use Illuminate\Support\Carbon;

/**
 * Centralized promotion eligibility + discount selection.
 * Default policy: promotions do not stack; the highest eligible discount wins.
 * Stacking can be introduced later via $options['stack'] without rewriting callers.
 */
final class PromotionPricingEngine
{
    /**
     * @param  array{bookingDate?:Carbon|string|null, bookingSource?:string|null, stack?:bool}  $options
     * @return array{
     *   originalAmount: float,
     *   discountAmount: float,
     *   finalAmount: float,
     *   promotion: ?Promotion,
     *   promotionName: ?string,
     *   promotionType: ?string,
     *   selectionReason: ?string,
     *   candidates: list<array{type:string,name:string,discountAmount:float,eligible:bool,reason:string}>
     * }
     */
    public static function apply(string $userUuid, float $baseAmount, int $nights, Carbon $checkIn, array $options = []): array
    {
        $base = round(max(0.0, $baseAmount), 2);
        $bookingDate = self::asDay($options['bookingDate'] ?? now());
        $checkInDay = $checkIn->copy()->startOfDay();
        $source = (string) ($options['bookingSource'] ?? Booking::SOURCE_DIRECT_PORTAL);
        $stack = (bool) ($options['stack'] ?? false);

        $promotions = Promotion::query()
            ->forUser($userUuid)
            ->whereIn('promotion_type', Promotion::TYPES)
            ->get();

        $candidates = [];
        $eligible = [];

        foreach ($promotions as $promo) {
            /** @var Promotion $promo */
            $result = self::evaluate($promo, $base, $nights, $bookingDate, $checkInDay, $source);
            $candidates[] = [
                'type' => $promo->promotion_type,
                'name' => $promo->displayName(),
                'discountAmount' => $result['discountAmount'],
                'eligible' => $result['eligible'],
                'reason' => $result['reason'],
            ];

            if ($result['eligible'] && $result['discountAmount'] > 0) {
                $eligible[] = [
                    'promotion' => $promo,
                    'discountAmount' => $result['discountAmount'],
                    'reason' => $result['reason'],
                ];
            }
        }

        if ($eligible === []) {
            return [
                'originalAmount' => $base,
                'discountAmount' => 0.0,
                'finalAmount' => $base,
                'promotion' => null,
                'promotionName' => null,
                'promotionType' => null,
                'selectionReason' => 'No eligible promotions',
                'candidates' => $candidates,
            ];
        }

        if ($stack) {
            // Future stacking path: sum capped discounts without rewriting call sites.
            $discount = 0.0;
            $names = [];
            foreach ($eligible as $row) {
                $remaining = round(max(0.0, $base - $discount), 2);
                $add = min($remaining, (float) $row['discountAmount']);
                $discount = round($discount + $add, 2);
                $names[] = $row['promotion']->displayName();
            }
            $discount = min($base, $discount);
            /** @var Promotion $first */
            $first = $eligible[0]['promotion'];

            return [
                'originalAmount' => $base,
                'discountAmount' => $discount,
                'finalAmount' => round(max(0.0, $base - $discount), 2),
                'promotion' => $first,
                'promotionName' => implode(' + ', $names),
                'promotionType' => $first->promotion_type,
                'selectionReason' => 'Stacked eligible promotions',
                'candidates' => $candidates,
            ];
        }

        usort($eligible, static function (array $a, array $b): int {
            $cmp = $b['discountAmount'] <=> $a['discountAmount'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['promotion']->promotion_type, (string) $b['promotion']->promotion_type);
        });

        $winner = $eligible[0];
        /** @var Promotion $promo */
        $promo = $winner['promotion'];
        $discount = min($base, (float) $winner['discountAmount']);

        return [
            'originalAmount' => $base,
            'discountAmount' => $discount,
            'finalAmount' => round(max(0.0, $base - $discount), 2),
            'promotion' => $promo,
            'promotionName' => $promo->displayName(),
            'promotionType' => $promo->promotion_type,
            'selectionReason' => sprintf(
                'Highest eligible discount (%.2f). %s',
                $discount,
                $winner['reason']
            ),
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array{eligible:bool, discountAmount:float, reason:string}
     */
    public static function evaluate(
        Promotion $promo,
        float $baseAmount,
        int $nights,
        Carbon $bookingDate,
        Carbon $checkIn,
        string $bookingSource,
    ): array {
        $meta = $promo->meta();
        $base = round(max(0.0, $baseAmount), 2);

        if (! $promo->is_enabled) {
            return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Disabled'];
        }

        if ((float) $promo->discount_value <= 0) {
            return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Discount value not configured'];
        }

        if (! empty($meta['hasDuration'])) {
            if ($promo->start_date && $bookingDate->lt($promo->start_date->copy()->startOfDay())) {
                return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Scheduled for later'];
            }
            if (! $promo->no_end_date && $promo->end_date && $bookingDate->gt($promo->end_date->copy()->startOfDay())) {
                return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Expired'];
            }
        }

        $advanceDays = (int) $bookingDate->diffInDays($checkIn, false);
        if ($checkIn->lt($bookingDate)) {
            $advanceDays = -1;
        }

        if ($promo->promotion_type === Promotion::TYPE_EARLY_BIRD) {
            $minDays = (int) ($promo->eligibility_days ?? 0);
            if ($minDays < 1 || $advanceDays < $minDays) {
                return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Advance booking period not met'];
            }
        }

        if ($promo->promotion_type === Promotion::TYPE_LAST_MINUTE) {
            $maxDays = (int) ($promo->eligibility_days ?? 0);
            if ($maxDays < 1 || $advanceDays < 0 || $advanceDays > $maxDays) {
                return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Outside last-minute window'];
            }
        }

        if ($promo->promotion_type === Promotion::TYPE_WEEKLY_RATE) {
            $minNights = (int) ($promo->minimum_nights ?: 7);
            if ($nights < $minNights) {
                return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Requires at least 7 nights'];
            }
        }

        if ($promo->promotion_type === Promotion::TYPE_MONTHLY_RATE) {
            $minNights = (int) ($promo->minimum_nights ?: 30);
            if ($nights < $minNights) {
                return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Requires at least 30 nights'];
            }
        }

        if ($promo->promotion_type === Promotion::TYPE_MOBILE_APP) {
            if ($bookingSource !== Booking::SOURCE_MOBILE_APP) {
                return ['eligible' => false, 'discountAmount' => 0.0, 'reason' => 'Mobile app bookings only'];
            }
        }

        $discount = self::discountAmount($promo, $base, max(1, $nights));

        return [
            'eligible' => $discount > 0,
            'discountAmount' => $discount,
            'reason' => 'Eligible',
        ];
    }

    public static function discountAmount(Promotion $promo, float $baseAmount, int $nights): float
    {
        $base = round(max(0.0, $baseAmount), 2);
        $value = (float) $promo->discount_value;
        $scope = $promo->meta()['fixedDiscountScope'] ?? 'booking';

        if ($promo->discount_type === Promotion::DISCOUNT_PERCENTAGE) {
            $raw = $base * ($value / 100);
        } elseif ($scope === 'night') {
            $raw = $value * max(1, $nights);
        } else {
            $raw = $value;
        }

        return round(min($base, max(0.0, $raw)), 2);
    }

    private static function asDay(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }
        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->startOfDay();
        }

        return now()->startOfDay();
    }
}
