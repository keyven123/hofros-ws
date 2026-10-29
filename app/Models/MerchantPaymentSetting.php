<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantPaymentSetting extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'accept_cash',
        'accept_gcash',
        'accept_bank_transfer',
        'accept_card',
        'gcash_name',
        'gcash_number',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'currency',
        'deposit_required',
        'deposit_percent',
        'instructions',
    ];

    protected function casts(): array
    {
        return [
            'accept_cash' => 'boolean',
            'accept_gcash' => 'boolean',
            'accept_bank_transfer' => 'boolean',
            'accept_card' => 'boolean',
            'deposit_required' => 'boolean',
            'deposit_percent' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public static function ensureForUser(User $user): self
    {
        return self::firstOrCreate(
            ['user_uuid' => $user->uuid],
            [
                'accept_cash' => true,
                'accept_gcash' => false,
                'accept_bank_transfer' => false,
                'accept_card' => false,
                'currency' => 'PHP',
                'deposit_required' => false,
                'deposit_percent' => 0,
            ],
        );
    }
}
