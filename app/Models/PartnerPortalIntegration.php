<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

class PartnerPortalIntegration extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    /**
     * @var array<string, array{name: string, defaultBaseUrl: ?string}>
     */
    public const CATALOG = [
        'ticketoc' => [
            'name' => 'Ticketoc',
            'defaultBaseUrl' => null,
        ],
        'booking_com' => [
            'name' => 'Booking.com',
            'defaultBaseUrl' => null,
        ],
        'airbnb' => [
            'name' => 'Airbnb',
            'defaultBaseUrl' => 'https://www.airbnb.com',
        ],
        'agoda' => [
            'name' => 'Agoda',
            'defaultBaseUrl' => null,
        ],
        'tripadvisor' => [
            'name' => 'Trip.com',
            'defaultBaseUrl' => null,
        ],
    ];

    protected $fillable = [
        'portal_key',
        'name',
        'api_base_url',
        'is_enabled',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * @return Collection<int, self>
     */
    public static function ensureCatalog(): Collection
    {
        foreach (self::CATALOG as $portalKey => $meta) {
            static::query()->firstOrCreate(
                ['portal_key' => $portalKey],
                [
                    'name' => $meta['name'],
                    'api_base_url' => $portalKey === 'ticketoc'
                        ? (string) config('services.ticketoc.base_url')
                        : $meta['defaultBaseUrl'],
                    'is_enabled' => true,
                ]
            );
        }

        $order = array_keys(self::CATALOG);

        return static::query()
            ->whereIn('portal_key', $order)
            ->get()
            ->sortBy(fn (self $row) => array_search($row->portal_key, $order, true))
            ->values();
    }

    public static function forPortal(string $portalKey): ?self
    {
        self::ensureCatalog();

        return static::query()->where('portal_key', $portalKey)->first();
    }

    public static function resolveBaseUrl(string $portalKey): ?string
    {
        $row = self::forPortal($portalKey);
        $url = $row?->api_base_url;
        if (is_string($url) && trim($url) !== '') {
            return rtrim(trim($url), '/');
        }

        if ($portalKey === 'ticketoc') {
            $fallback = (string) config('services.ticketoc.base_url');

            return $fallback !== '' ? rtrim($fallback, '/') : null;
        }

        // Airbnb merchant connect is User ID based; default to the public guest site
        // GraphQL host so listing capture works without a custom admin URL.
        if ($portalKey === 'airbnb') {
            $fallback = (string) config('services.airbnb.base_url', 'https://www.airbnb.com');

            return $fallback !== '' ? rtrim($fallback, '/') : 'https://www.airbnb.com';
        }

        return null;
    }

    public static function isEnabled(string $portalKey): bool
    {
        $row = self::forPortal($portalKey);

        return $row ? (bool) $row->is_enabled : false;
    }

    /**
     * Partner key saved in admin, decrypted. Does not fall back to the environment.
     */
    public function storedOtaPartnerKey(): ?string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $stored = trim((string) ($meta['ota_partner_key'] ?? ''));
        if ($stored === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($stored);

            return $plain !== '' ? $plain : null;
        } catch (\Throwable) {
            return $stored;
        }
    }

    /**
     * Key Ticketoc sends when it calls Hofros Stay. Admin value wins over the environment.
     */
    public function otaPartnerKey(): ?string
    {
        return $this->storedOtaPartnerKey()
            ?: (($fallback = trim((string) config('services.ticketoc.ota_partner_key'))) !== '' ? $fallback : null);
    }

    public function setOtaPartnerKey(string $key): void
    {
        $key = trim($key);
        $meta = is_array($this->meta) ? $this->meta : [];
        $meta['ota_partner_key'] = Crypt::encryptString($key);
        $meta['stay_partner_prefix'] = substr($key, 0, 12);
        $meta['stay_status'] = 'saved';
        $meta['stay_last_error'] = null;
        $meta['stay_last_tested_at'] = null;
        $this->meta = $meta;
    }

    public function markStay(string $status, ?string $error): void
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $meta['stay_status'] = $status;
        $meta['stay_last_error'] = $error;
        $meta['stay_last_tested_at'] = now()->toIso8601String();
        $this->meta = $meta;
        $this->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        $payload = [
            'uuid' => $this->uuid,
            'portalKey' => $this->portal_key,
            'name' => $this->name,
            'apiBaseUrl' => $this->api_base_url,
            'isEnabled' => (bool) $this->is_enabled,
            'isConfigured' => filled($this->api_base_url),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->portal_key === 'ticketoc') {
            $meta = is_array($this->meta) ? $this->meta : [];
            $key = $this->otaPartnerKey();
            $payload['stayPartnerKeySet'] = $key !== null;
            $payload['stayPartnerKeyPrefix'] = is_string($meta['stay_partner_prefix'] ?? null) && $meta['stay_partner_prefix'] !== ''
                ? $meta['stay_partner_prefix']
                : ($key !== null ? substr($key, 0, 12) : null);
            $payload['stayStatus'] = (string) ($meta['stay_status'] ?? ($key !== null ? 'saved' : 'missing'));
            $payload['stayLastError'] = $meta['stay_last_error'] ?? null;
            $payload['stayLastTestedAt'] = $meta['stay_last_tested_at'] ?? null;
            $payload['stayInboundUrl'] = rtrim((string) (request()?->getSchemeAndHttpHost() ?: config('app.url')), '/').'/api';
        }

        return $payload;
    }
}
