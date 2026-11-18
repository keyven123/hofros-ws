<?php

namespace App\Http\Resources;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Promotion $promo */
        $promo = $this->resource;
        $meta = $promo->meta();
        $status = $promo->status();

        return [
            'id' => $promo->uuid,
            'promotionType' => $promo->promotion_type,
            'name' => $promo->displayName(),
            'description' => $meta['description'] ?? '',
            'isEnabled' => (bool) $promo->is_enabled,
            'status' => $status,
            'statusLabel' => match ($status) {
                'active' => 'Active',
                'scheduled' => 'Scheduled',
                'expired' => 'Expired',
                default => 'Disabled',
            },
            'startDate' => optional($promo->start_date)->toDateString(),
            'endDate' => optional($promo->end_date)->toDateString(),
            'noEndDate' => (bool) $promo->no_end_date,
            'eligibilityDays' => $promo->eligibility_days,
            'minimumNights' => $promo->minimum_nights,
            'discountType' => $promo->discount_type,
            'discountValue' => (float) $promo->discount_value,
            'hasDuration' => (bool) ($meta['hasDuration'] ?? false),
            'hasEligibilityDays' => (bool) ($meta['hasEligibilityDays'] ?? false),
            'eligibilityLabel' => $meta['eligibilityLabel'] ?? null,
            'fixedDiscountScope' => $meta['fixedDiscountScope'] ?? 'booking',
            'discountSummary' => $this->discountSummary($promo, $meta),
            'durationSummary' => $this->durationSummary($promo, $meta),
            'updatedAt' => $promo->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function discountSummary(Promotion $promo, array $meta): string
    {
        $value = (float) $promo->discount_value;
        if ($value <= 0) {
            return 'Not configured';
        }
        if ($promo->discount_type === Promotion::DISCOUNT_PERCENTAGE) {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'% off';
        }
        $amount = '₱'.number_format($value, 0, '.', ',');
        if (($meta['fixedDiscountScope'] ?? 'booking') === 'night') {
            return $amount.' off per night';
        }

        return $amount.' off per booking';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function durationSummary(Promotion $promo, array $meta): ?string
    {
        if (empty($meta['hasDuration'])) {
            return null;
        }
        if (! $promo->start_date) {
            return 'No schedule set';
        }
        if ($promo->no_end_date || ! $promo->end_date) {
            return 'From '.$promo->start_date->toDateString().' · No end date';
        }

        return $promo->start_date->toDateString().' → '.$promo->end_date->toDateString();
    }
}
