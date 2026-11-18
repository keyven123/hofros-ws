<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchantPaymentGateway extends Model
{
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const PROVIDER_PAYMONGO = 'paymongo';
    public const PROVIDER_PAYPAL = 'paypal';
    public const PROVIDER_PAYNAMICS = 'paynamics';

    public const PROVIDERS = [
        self::PROVIDER_PAYMONGO,
        self::PROVIDER_PAYPAL,
        self::PROVIDER_PAYNAMICS,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const ENV_TESTING = 'testing';
    public const ENV_PRODUCTION = 'production';

    protected $fillable = [
        'user_uuid',
        'provider',
        'name',
        'environment',
        'public_key',
        'secret_key',
        'api_username',
        'api_password',
        'status',
    ];

    protected $hidden = [
        'public_key',
        'secret_key',
        'api_username',
        'api_password',
    ];

    protected function casts(): array
    {
        return [
            'public_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'api_username' => 'encrypted',
            'api_password' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function scopeForUser(Builder $query, string $userUuid): Builder
    {
        return $query->where('user_uuid', $userUuid);
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public static function activeForUser(string $userUuid): ?self
    {
        return self::query()->forUser($userUuid)->online()->latest('updated_at')->first();
    }

    public static function maskCredential(?string $value, int $visiblePrefix = 0): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        $prefix = $visiblePrefix > 0 ? substr($value, 0, $visiblePrefix) : '';

        return $prefix.str_repeat('•', 8).substr($value, -4);
    }

    public function detectEnvironment(): string
    {
        if ($this->provider === self::PROVIDER_PAYMONGO) {
            $blob = strtolower((string) $this->public_key.' '.(string) $this->secret_key);
            if (str_contains($blob, 'live')) {
                return self::ENV_PRODUCTION;
            }

            return self::ENV_TESTING;
        }

        return in_array($this->environment, [self::ENV_TESTING, self::ENV_PRODUCTION], true)
            ? $this->environment
            : self::ENV_TESTING;
    }
}
