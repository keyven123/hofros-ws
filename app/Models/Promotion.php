<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Promotion extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const TYPE_EARLY_BIRD = 'early_bird';
    public const TYPE_LAST_MINUTE = 'last_minute';
    public const TYPE_WEEKLY_RATE = 'weekly_rate';
    public const TYPE_MONTHLY_RATE = 'monthly_rate';
    public const TYPE_MOBILE_APP = 'mobile_app';

    public const TYPES = [
        self::TYPE_EARLY_BIRD,
        self::TYPE_LAST_MINUTE,
        self::TYPE_WEEKLY_RATE,
        self::TYPE_MONTHLY_RATE,
        self::TYPE_MOBILE_APP,
    ];

    public const DISCOUNT_PERCENTAGE = 'percentage';
    public const DISCOUNT_FIXED = 'fixed_amount';

    public const DISCOUNT_TYPES = [
        self::DISCOUNT_PERCENTAGE,
        self::DISCOUNT_FIXED,
    ];

    public const META = [
        self::TYPE_EARLY_BIRD => [
            'name' => 'Early Bird Promotion',
            'description' => 'Book in advance and save more.',
            'hasDuration' => true,
            'hasEligibilityDays' => true,
            'eligibilityLabel' => 'Minimum advance booking (days before check-in)',
            'fixedDiscountScope' => 'booking',
            'defaultMinimumNights' => null,
        ],
        self::TYPE_LAST_MINUTE => [
            'name' => 'Last Minute Promotion',
            'description' => 'Exclusive discounts for last-minute bookings.',
            'hasDuration' => true,
            'hasEligibilityDays' => true,
            'eligibilityLabel' => 'Maximum advance booking (days before check-in)',
            'fixedDiscountScope' => 'booking',
            'defaultMinimumNights' => null,
        ],
        self::TYPE_WEEKLY_RATE => [
            'name' => 'Weekly Rate',
            'description' => 'Special rates for stays of 7 nights or more.',
            'hasDuration' => false,
            'hasEligibilityDays' => false,
            'eligibilityLabel' => null,
            'fixedDiscountScope' => 'night',
            'defaultMinimumNights' => 7,
        ],
        self::TYPE_MONTHLY_RATE => [
            'name' => 'Monthly Rate',
            'description' => 'Special rates for stays of 30 nights or more.',
            'hasDuration' => false,
            'hasEligibilityDays' => false,
            'eligibilityLabel' => null,
            'fixedDiscountScope' => 'night',
            'defaultMinimumNights' => 30,
        ],
        self::TYPE_MOBILE_APP => [
            'name' => 'Hofros Mobile App Discount',
            'description' => 'Exclusive mobile app booking discounts.',
            'hasDuration' => true,
            'hasEligibilityDays' => false,
            'eligibilityLabel' => null,
            'fixedDiscountScope' => 'booking',
            'defaultMinimumNights' => null,
        ],
    ];

    protected $fillable = [
        'user_uuid',
        'promotion_type',
        'name',
        'is_enabled',
        'start_date',
        'end_date',
        'no_end_date',
        'eligibility_days',
        'minimum_nights',
        'discount_type',
        'discount_value',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'no_end_date' => 'boolean',
            'eligibility_days' => 'integer',
            'minimum_nights' => 'integer',
            'discount_value' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, string $userUuid): Builder
    {
        return $query->where('user_uuid', $userUuid);
    }

    public function meta(): array
    {
        return self::META[$this->promotion_type] ?? [
            'name' => $this->promotion_type,
            'description' => '',
            'hasDuration' => false,
            'hasEligibilityDays' => false,
            'eligibilityLabel' => null,
            'fixedDiscountScope' => 'booking',
            'defaultMinimumNights' => null,
        ];
    }

    public function displayName(): string
    {
        $custom = trim((string) $this->name);
        if ($custom !== '') {
            return $custom;
        }

        return (string) ($this->meta()['name'] ?? $this->promotion_type);
    }

    public function status(?Carbon $on = null): string
    {
        $on = ($on ?? now())->copy()->startOfDay();

        if (! $this->is_enabled) {
            return 'disabled';
        }

        $meta = $this->meta();
        if (! empty($meta['hasDuration'])) {
            if ($this->start_date && $this->start_date->gt($on)) {
                return 'scheduled';
            }
            if (! $this->no_end_date && $this->end_date && $this->end_date->lt($on)) {
                return 'expired';
            }
        }

        return 'active';
    }
}
