<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'portalKey' => $this->portal_key,
            'name' => $this->name,
            'apiBaseUrl' => $this->api_base_url,
            'isEnabled' => (bool) $this->is_enabled,
            'isConfigured' => filled($this->api_base_url),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
