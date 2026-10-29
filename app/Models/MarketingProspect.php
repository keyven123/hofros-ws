<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingProspect extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    public const KIND_MARKET = 'market';

    public const KIND_CLIENT = 'client';

    public const KINDS = [
        self::KIND_MARKET,
        self::KIND_CLIENT,
    ];

    public const STATUS_TARGET = 'target';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_INTERESTED = 'interested';

    public const STATUS_ONBOARDED = 'onboarded';

    public const STATUS_PASSED = 'passed';

    public const STATUSES = [
        self::STATUS_TARGET,
        self::STATUS_CONTACTED,
        self::STATUS_INTERESTED,
        self::STATUS_ONBOARDED,
        self::STATUS_PASSED,
    ];

    protected $fillable = [
        'owner_user_uuid',
        'kind',
        'name',
        'contact_name',
        'email',
        'phone',
        'location',
        'rooms',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rooms' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_uuid', 'uuid');
    }

    public function noteEntries(): HasMany
    {
        return $this->hasMany(MarketingProspectNote::class, 'prospect_uuid', 'uuid')
            ->orderByDesc('created_at')
            ->orderByDesc('uuid');
    }
}
