<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionBillingSetting extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    public const DEFAULT_YEARLY_DISCOUNT = 66;

    public const DEFAULT_TWO_YEAR_DISCOUNT = 70;

    public const DEFAULT_FREE_TRIAL_DAYS = 30;

    protected $fillable = [
        'yearly_discount_percent',
        'two_year_discount_percent',
        'free_trial_days',
    ];

    protected function casts(): array
    {
        return [
            'yearly_discount_percent' => 'integer',
            'two_year_discount_percent' => 'integer',
            'free_trial_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        $row = static::query()->orderBy('created_at')->first();
        if ($row) {
            return $row;
        }

        return static::query()->create([
            'yearly_discount_percent' => self::DEFAULT_YEARLY_DISCOUNT,
            'two_year_discount_percent' => self::DEFAULT_TWO_YEAR_DISCOUNT,
            'free_trial_days' => self::DEFAULT_FREE_TRIAL_DAYS,
        ]);
    }

    public function freeTrialDays(): int
    {
        $days = (int) ($this->free_trial_days ?: self::DEFAULT_FREE_TRIAL_DAYS);

        return max(1, min(365, $days));
    }

    /**
     * @return list<array{id: string, label: string, months: int, discountPercent: int}>
     */
    public function billingCycles(): array
    {
        return [
            [
                'id' => 'monthly',
                'label' => 'Monthly',
                'months' => 1,
                'discountPercent' => 0,
            ],
            [
                'id' => 'yearly',
                'label' => 'Yearly',
                'months' => 12,
                'discountPercent' => (int) $this->yearly_discount_percent,
            ],
            [
                'id' => 'two_year',
                'label' => '2-Year',
                'months' => 24,
                'discountPercent' => (int) $this->two_year_discount_percent,
            ],
        ];
    }
}
