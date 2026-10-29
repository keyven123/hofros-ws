<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    public const MODULES = [
        'dashboard' => 'Home',
        'calendar' => 'Calendar',
        'bookings' => 'Bookings',
        'discount' => 'Discount',
        'messages' => 'Messages',
        'customers' => 'Customers',
        'analytics' => 'Analytics',
        'portals' => 'Booking Portals',
        'configuration' => 'Configuration',
        'advanced' => 'Advanced',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_per_room',
        'base_price',
        'currency',
        'max_rooms',
        'modules',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_per_room' => 'decimal:2',
            'base_price' => 'decimal:2',
            'max_rooms' => 'integer',
            'modules' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MerchantSubscription::class, 'subscription_plan_uuid', 'uuid');
    }
}
