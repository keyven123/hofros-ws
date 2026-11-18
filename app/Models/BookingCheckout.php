<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCheckout extends Model
{
    use HasUuids;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_uuid',
        'payment_gateway_uuid',
        'provider',
        'portal_batch_id',
        'booking_uuid',
        'reference',
        'amount',
        'currency',
        'status',
        'provider_session_id',
        'checkout_url',
        'metadata',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(MerchantPaymentGateway::class, 'payment_gateway_uuid', 'uuid');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_uuid', 'uuid');
    }
}
