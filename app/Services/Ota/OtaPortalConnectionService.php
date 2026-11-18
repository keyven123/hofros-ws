<?php

namespace App\Services\Ota;

use App\Http\Repositories\BookingPortalRepository;
use App\Http\Repositories\UnitRepository;
use App\Models\BookingPortalConnection;
use App\Models\PartnerPortalIntegration;
use App\Support\OtaPortalSchemas;
use RuntimeException;

final class OtaPortalConnectionService
{
    public function __construct(
        private BookingPortalRepository $portalRepository,
        private UnitRepository $unitRepository,
        private AirbnbClient $airbnbClient,
    ) {
    }

    public function assertSupported(string $portalKey): void
    {
        if (! OtaPortalSchemas::isSupported($portalKey)) {
            throw new RuntimeException('This portal does not use OTA credential connect.');
        }
    }

    public function resolvePlatformBaseUrl(string $portalKey): string
    {
        if (! PartnerPortalIntegration::isEnabled($portalKey)) {
            throw new RuntimeException(OtaPortalSchemas::for($portalKey)['name'].' partner integration is disabled by the platform admin.');
        }

        $baseUrl = PartnerPortalIntegration::resolveBaseUrl($portalKey);
        if ($baseUrl === null || $baseUrl === '') {
            throw new RuntimeException(
                OtaPortalSchemas::for($portalKey)['name'].' API base URL is not configured. Ask a Hofros admin to set it under Partner integrations.'
            );
        }

        return $baseUrl;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, portalKey: string, propertyLabel: string, fieldsReceived: int}
     */
    public function test(string $portalKey, array $input, ?BookingPortalConnection $existing = null): array
    {
        $this->assertSupported($portalKey);
        $baseUrl = $this->resolvePlatformBaseUrl($portalKey);
        $credentials = $this->normalizeCredentials($portalKey, $input, $existing);

        return [
            'ok' => true,
            'portalKey' => $portalKey,
            'propertyLabel' => $this->propertyLabel($portalKey, $credentials),
            'fieldsReceived' => count(array_filter($credentials, fn ($value) => $value !== null && $value !== '')),
            'baseUrl' => $baseUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function connect(string $userUuid, string $portalKey, array $input): BookingPortalConnection
    {
        $this->assertSupported($portalKey);
        $baseUrl = $this->resolvePlatformBaseUrl($portalKey);
        $row = $this->portalRepository->findOrFail($userUuid, $portalKey);
        $credentials = $this->normalizeCredentials($portalKey, $input, $row);

        $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
        $meta['environment'] = str_contains(strtolower($baseUrl), 'localhost') || str_contains(strtolower($baseUrl), '127.0.0.1')
            ? 'testing'
            : 'production';
        $meta['last_validated_at'] = now()->toIso8601String();
        $meta['last_error'] = null;
        $meta['ota_public'] = $this->publicCredentialSnapshot($portalKey, $credentials);

        $primarySecret = $this->primarySecret($portalKey, $credentials);
        $listingCount = max(1, (int) $row->listing_count);

        if ($portalKey === 'airbnb') {
            $hostUserId = (string) ($credentials['userId'] ?? '');
            try {
                $listings = $this->captureAirbnbListingsForUser($hostUserId, $baseUrl);
            } catch (RuntimeException $e) {
                $meta['last_error'] = $e->getMessage();
                $row->update([
                    'has_sync_issue' => true,
                    'connection_meta' => $meta,
                ]);
                throw $e;
            }

            $meta = $this->upsertAirbnbHost($meta, $hostUserId, $listings, true);
            $meta['unit_mappings'] = is_array($meta['unit_mappings'] ?? null) ? $meta['unit_mappings'] : [];
            $listingCount = $this->countActiveAirbnbListings($meta);
            $credentials = $this->airbnbCredentialsFromHosts($meta, $hostUserId);
        }

        $row->update([
            'is_connected' => true,
            'is_active' => true,
            'listing_count' => $listingCount,
            'last_synced_at' => now(),
            'has_sync_issue' => false,
            'external_base_url' => $baseUrl,
            'external_merchant_email' => $this->accountIdentifier($portalKey, $credentials),
            'external_api_secret' => $primarySecret,
            'external_credentials' => $credentials,
            'external_organization_uuid' => $this->propertyId($portalKey, $credentials),
            'external_organization_name' => $this->propertyLabel($portalKey, $credentials),
            'connection_meta' => $meta,
        ]);

        return $row->fresh();
    }

    public function disconnect(string $userUuid, string $portalKey): BookingPortalConnection
    {
        $this->assertSupported($portalKey);
        $row = $this->portalRepository->findOrFail($userUuid, $portalKey);

        $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
        $meta['last_error'] = null;
        $meta['disconnected_at'] = now()->toIso8601String();
        unset(
            $meta['ota_public'],
            $meta['airbnb_listings'],
            $meta['airbnb_listings_captured_at'],
            $meta['airbnb_hosts'],
            $meta['unit_mappings']
        );

        $row->update([
            'is_connected' => false,
            'is_active' => false,
            'listing_count' => 0,
            'has_sync_issue' => false,
            'external_base_url' => null,
            'external_merchant_email' => null,
            'external_api_secret' => null,
            'external_credentials' => null,
            'external_access_token' => null,
            'external_token_expires_at' => null,
            'external_organization_uuid' => null,
            'external_organization_name' => null,
            'connection_meta' => $meta,
        ]);

        return $row->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function airbnbAccommodations(string $userUuid, ?string $selectedUserId = null): array
    {
        $row = $this->requireConnectedAirbnb($userUuid);
        $meta = $this->ensureAirbnbHostsMeta($row);
        $hosts = $this->airbnbHostsFromMeta($meta);
        if ($hosts === []) {
            throw new RuntimeException('Add at least one Airbnb User ID before managing accommodations.');
        }

        $selected = $this->resolveAirbnbHost($hosts, $selectedUserId);
        $selectedId = (string) $selected['userId'];
        $listings = is_array($selected['listings'] ?? null) ? $selected['listings'] : [];
        $hostListingIds = array_fill_keys(array_column($listings, 'id'), true);
        $mappings = is_array($meta['unit_mappings'] ?? null) ? $meta['unit_mappings'] : [];

        $units = $this->unitRepository
            ->getAll(['user_uuid' => $userUuid])
            ->get()
            ->map(function ($unit) use ($mappings, $hostListingIds) {
                $mapped = $mappings[$unit->uuid] ?? null;
                $mappedToThisHost = is_string($mapped) && isset($hostListingIds[$mapped]);

                return [
                    'uuid' => $unit->uuid,
                    'name' => $unit->name,
                    'propertyName' => $unit->property?->property_name,
                    'mappedListingId' => $mappedToThisHost ? $mapped : null,
                    'mappedElsewhere' => is_string($mapped) && ! $mappedToThisHost,
                ];
            })
            ->values()
            ->all();

        return [
            'portalKey' => 'airbnb',
            'isConnected' => true,
            'userId' => $selectedId,
            'hosts' => array_map(fn (array $host) => $this->publicAirbnbHost($host), $hosts),
            'hostCount' => count($hosts),
            'activeHostCount' => count(array_filter($hosts, fn (array $host) => ! empty($host['isActive']))),
            'listings' => $listings,
            'units' => $units,
            'mappings' => array_filter(
                $mappings,
                fn ($listingId) => is_string($listingId) && isset($hostListingIds[$listingId])
            ),
            'capturedAt' => $selected['capturedAt'] ?? null,
            'listingCount' => count($listings),
            'totalListingCount' => $this->countActiveAirbnbListings($meta),
            'isActive' => ! empty($selected['isActive']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function addAirbnbHost(string $userUuid, string $hostUserId): array
    {
        $row = $this->requireConnectedAirbnb($userUuid);
        $hostUserId = trim($hostUserId);
        if ($hostUserId === '' || ! preg_match('/^\d{5,20}$/', $hostUserId)) {
            throw new RuntimeException('Airbnb User ID must be a numeric host ID.');
        }

        $meta = $this->ensureAirbnbHostsMeta($row);
        $hosts = $this->airbnbHostsFromMeta($meta);
        foreach ($hosts as $host) {
            if ((string) ($host['userId'] ?? '') === $hostUserId) {
                throw new RuntimeException('This Airbnb User ID is already connected.');
            }
        }

        $baseUrl = PartnerPortalIntegration::resolveBaseUrl('airbnb');
        try {
            $listings = $this->captureAirbnbListingsForUser($hostUserId, $baseUrl);
        } catch (RuntimeException $e) {
            $meta['last_error'] = $e->getMessage();
            $row->update([
                'has_sync_issue' => true,
                'connection_meta' => $meta,
            ]);
            throw $e;
        }

        $meta = $this->upsertAirbnbHost($meta, $hostUserId, $listings, true);
        $credentials = $this->airbnbCredentialsFromHosts($meta, $hostUserId);
        $row->update([
            'listing_count' => $this->countActiveAirbnbListings($meta),
            'last_synced_at' => now(),
            'has_sync_issue' => false,
            'external_credentials' => $credentials,
            'external_merchant_email' => $hostUserId,
            'external_organization_uuid' => $hostUserId,
            'external_organization_name' => 'Airbnb · '.$hostUserId,
            'connection_meta' => $meta,
        ]);

        return $this->airbnbAccommodations($userUuid, $hostUserId);
    }

    /**
     * @return array<string, mixed>
     */
    public function setAirbnbHostActive(string $userUuid, string $hostUserId, bool $isActive): array
    {
        $row = $this->requireConnectedAirbnb($userUuid);
        $meta = $this->ensureAirbnbHostsMeta($row);
        $hosts = $this->airbnbHostsFromMeta($meta);
        $found = false;

        foreach ($hosts as &$host) {
            if ((string) ($host['userId'] ?? '') !== trim($hostUserId)) {
                continue;
            }
            $host['isActive'] = $isActive;
            $found = true;
            break;
        }
        unset($host);

        if (! $found) {
            throw new RuntimeException('Airbnb User ID was not found.');
        }

        $meta['airbnb_hosts'] = array_values($hosts);
        $meta = $this->syncLegacyAirbnbListings($meta);
        $credentials = $this->airbnbCredentialsFromHosts($meta, trim($hostUserId));

        $row->update([
            'listing_count' => $this->countActiveAirbnbListings($meta),
            'external_credentials' => $credentials,
            'external_merchant_email' => $credentials['userId'] ?? null,
            'external_organization_uuid' => $credentials['userId'] ?? null,
            'external_organization_name' => isset($credentials['userId']) ? 'Airbnb · '.$credentials['userId'] : 'Airbnb',
            'connection_meta' => $meta,
        ]);

        return $this->airbnbAccommodations($userUuid, $hostUserId);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeAirbnbHost(string $userUuid, string $hostUserId): array
    {
        $row = $this->requireConnectedAirbnb($userUuid);
        $meta = $this->ensureAirbnbHostsMeta($row);
        $hosts = $this->airbnbHostsFromMeta($meta);
        $hostUserId = trim($hostUserId);
        $removedListings = [];
        $remaining = [];

        foreach ($hosts as $host) {
            if ((string) ($host['userId'] ?? '') === $hostUserId) {
                $removedListings = is_array($host['listings'] ?? null) ? $host['listings'] : [];
                continue;
            }
            $remaining[] = $host;
        }

        if (count($remaining) === count($hosts)) {
            throw new RuntimeException('Airbnb User ID was not found.');
        }

        if ($remaining === []) {
            throw new RuntimeException('Keep at least one Airbnb User ID, or disconnect the Airbnb channel.');
        }

        $removedIds = array_fill_keys(array_column($removedListings, 'id'), true);
        $mappings = is_array($meta['unit_mappings'] ?? null) ? $meta['unit_mappings'] : [];
        $meta['unit_mappings'] = array_filter(
            $mappings,
            fn ($listingId) => is_string($listingId) && ! isset($removedIds[$listingId])
        );
        $meta['airbnb_hosts'] = array_values($remaining);
        $meta = $this->syncLegacyAirbnbListings($meta);
        $credentials = $this->airbnbCredentialsFromHosts($meta, null);

        $row->update([
            'listing_count' => $this->countActiveAirbnbListings($meta),
            'last_synced_at' => now(),
            'external_credentials' => $credentials,
            'external_merchant_email' => $credentials['userId'] ?? null,
            'external_organization_uuid' => $credentials['userId'] ?? null,
            'external_organization_name' => isset($credentials['userId']) ? 'Airbnb · '.$credentials['userId'] : 'Airbnb',
            'connection_meta' => $meta,
        ]);

        return $this->airbnbAccommodations($userUuid, $credentials['userId'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function reloadAirbnbListings(string $userUuid, ?string $hostUserId = null): array
    {
        $row = $this->requireConnectedAirbnb($userUuid);
        $meta = $this->ensureAirbnbHostsMeta($row);
        $hosts = $this->airbnbHostsFromMeta($meta);
        $selected = $this->resolveAirbnbHost($hosts, $hostUserId);
        $userId = (string) $selected['userId'];

        $baseUrl = PartnerPortalIntegration::resolveBaseUrl('airbnb');
        try {
            $listings = $this->captureAirbnbListingsForUser($userId, $baseUrl);
        } catch (RuntimeException $e) {
            $meta['last_error'] = $e->getMessage();
            $row->update([
                'has_sync_issue' => true,
                'connection_meta' => $meta,
            ]);
            throw $e;
        }

        $previousIds = array_fill_keys(array_column(is_array($selected['listings'] ?? null) ? $selected['listings'] : [], 'id'), true);
        $nextIds = array_fill_keys(array_column($listings, 'id'), true);
        $droppedIds = array_diff_key($previousIds, $nextIds);

        $meta = $this->upsertAirbnbHost($meta, $userId, $listings, (bool) ($selected['isActive'] ?? true));
        $mappings = is_array($meta['unit_mappings'] ?? null) ? $meta['unit_mappings'] : [];
        $meta['unit_mappings'] = array_filter(
            $mappings,
            fn ($listingId) => is_string($listingId) && ! isset($droppedIds[$listingId])
        );
        $meta['last_validated_at'] = now()->toIso8601String();
        $meta['last_error'] = null;

        $row->update([
            'listing_count' => $this->countActiveAirbnbListings($meta),
            'last_synced_at' => now(),
            'has_sync_issue' => false,
            'connection_meta' => $meta,
        ]);

        return $this->airbnbAccommodations($userUuid, $userId);
    }

    /**
     * @param  array<string, string|null>  $mappings unitUuid => listingId
     * @return array<string, mixed>
     */
    public function saveAirbnbMappings(string $userUuid, array $mappings, ?string $hostUserId = null): array
    {
        $row = $this->requireConnectedAirbnb($userUuid);
        $meta = $this->ensureAirbnbHostsMeta($row);
        $hosts = $this->airbnbHostsFromMeta($meta);
        $selected = $this->resolveAirbnbHost($hosts, $hostUserId);
        $listings = is_array($selected['listings'] ?? null) ? $selected['listings'] : [];
        $validIds = array_fill_keys(array_column($listings, 'id'), true);

        $unitIds = $this->unitRepository
            ->getAll(['user_uuid' => $userUuid])
            ->pluck('uuid')
            ->all();
        $unitLookup = array_fill_keys($unitIds, true);
        $existing = is_array($meta['unit_mappings'] ?? null) ? $meta['unit_mappings'] : [];

        foreach ($mappings as $unitUuid => $listingId) {
            if (! is_string($unitUuid) || ! isset($unitLookup[$unitUuid])) {
                continue;
            }

            $current = $existing[$unitUuid] ?? null;
            $currentBelongsHere = is_string($current) && isset($validIds[$current]);

            if ($listingId === null || $listingId === '') {
                if ($currentBelongsHere) {
                    unset($existing[$unitUuid]);
                }
                continue;
            }

            if (! is_string($listingId) || ! isset($validIds[$listingId])) {
                throw new RuntimeException('One or more selected Airbnb listings are invalid. Reload accommodations and try again.');
            }

            $existing[$unitUuid] = $listingId;
        }

        $meta['unit_mappings'] = $existing;
        $meta['last_validated_at'] = now()->toIso8601String();
        $row->update(['connection_meta' => $meta]);

        return $this->airbnbAccommodations($userUuid, (string) $selected['userId']);
    }

    private function requireConnectedAirbnb(string $userUuid): BookingPortalConnection
    {
        $row = $this->portalRepository->findOrFail($userUuid, 'airbnb');
        if (! $row->is_connected) {
            throw new RuntimeException('Connect Airbnb with your User ID before managing accommodations.');
        }

        return $row;
    }

    /**
     * @return list<array{id: string, title: string, location: string, label: string, pictureUrl?: ?string, roomType?: ?string}>
     */
    private function captureAirbnbListingsForUser(string $userId, ?string $configuredBaseUrl = null): array
    {
        return $this->airbnbClient->fetchHostListings($userId, $configuredBaseUrl);
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureAirbnbHostsMeta(BookingPortalConnection $row): array
    {
        $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
        $hosts = $this->airbnbHostsFromMeta($meta);
        if ($hosts !== []) {
            $meta['airbnb_hosts'] = $hosts;
            $meta = $this->syncLegacyAirbnbListings($meta);

            return $meta;
        }

        $creds = is_array($row->external_credentials) ? $row->external_credentials : [];
        $legacyUserId = trim((string) ($creds['userId'] ?? $row->external_organization_uuid ?? ''));
        $legacyListings = is_array($meta['airbnb_listings'] ?? null) ? $meta['airbnb_listings'] : [];

        if ($legacyUserId !== '' && $legacyListings !== []) {
            $meta = $this->upsertAirbnbHost($meta, $legacyUserId, $legacyListings, true);
            $row->update(['connection_meta' => $meta]);
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<array<string, mixed>>
     */
    private function airbnbHostsFromMeta(array $meta): array
    {
        $hosts = is_array($meta['airbnb_hosts'] ?? null) ? $meta['airbnb_hosts'] : [];
        $normalized = [];

        foreach ($hosts as $host) {
            if (! is_array($host)) {
                continue;
            }
            $userId = trim((string) ($host['userId'] ?? ''));
            if ($userId === '' || ! preg_match('/^\d{5,20}$/', $userId)) {
                continue;
            }
            $listings = is_array($host['listings'] ?? null) ? $host['listings'] : [];
            $normalized[] = [
                'userId' => $userId,
                'isActive' => array_key_exists('isActive', $host) ? (bool) $host['isActive'] : true,
                'listings' => $listings,
                'listingCount' => count($listings),
                'capturedAt' => $host['capturedAt'] ?? null,
                'lastError' => $host['lastError'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $hosts
     * @return array<string, mixed>
     */
    private function resolveAirbnbHost(array $hosts, ?string $selectedUserId): array
    {
        $selectedUserId = trim((string) $selectedUserId);
        if ($selectedUserId !== '') {
            foreach ($hosts as $host) {
                if ((string) ($host['userId'] ?? '') === $selectedUserId) {
                    return $host;
                }
            }
            throw new RuntimeException('Airbnb User ID was not found.');
        }

        foreach ($hosts as $host) {
            if (! empty($host['isActive'])) {
                return $host;
            }
        }

        return $hosts[0];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $listings
     * @return array<string, mixed>
     */
    private function upsertAirbnbHost(array $meta, string $userId, array $listings, bool $isActive): array
    {
        $hosts = $this->airbnbHostsFromMeta($meta);
        $found = false;
        $now = now()->toIso8601String();

        foreach ($hosts as &$host) {
            if ((string) ($host['userId'] ?? '') !== $userId) {
                continue;
            }
            $host['listings'] = $listings;
            $host['listingCount'] = count($listings);
            $host['capturedAt'] = $now;
            $host['isActive'] = $isActive;
            $host['lastError'] = null;
            $found = true;
            break;
        }
        unset($host);

        if (! $found) {
            $hosts[] = [
                'userId' => $userId,
                'isActive' => $isActive,
                'listings' => $listings,
                'listingCount' => count($listings),
                'capturedAt' => $now,
                'lastError' => null,
            ];
        }

        $meta['airbnb_hosts'] = array_values($hosts);
        $meta['unit_mappings'] = is_array($meta['unit_mappings'] ?? null) ? $meta['unit_mappings'] : [];

        return $this->syncLegacyAirbnbListings($meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function syncLegacyAirbnbListings(array $meta): array
    {
        $hosts = $this->airbnbHostsFromMeta($meta);
        $primary = null;
        foreach ($hosts as $host) {
            if (! empty($host['isActive'])) {
                $primary = $host;
                break;
            }
        }
        if ($primary === null && $hosts !== []) {
            $primary = $hosts[0];
        }

        $meta['airbnb_listings'] = is_array($primary['listings'] ?? null) ? $primary['listings'] : [];
        $meta['airbnb_listings_captured_at'] = $primary['capturedAt'] ?? null;

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function countActiveAirbnbListings(array $meta): int
    {
        $total = 0;
        foreach ($this->airbnbHostsFromMeta($meta) as $host) {
            if (empty($host['isActive'])) {
                continue;
            }
            $total += count(is_array($host['listings'] ?? null) ? $host['listings'] : []);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{userId: ?string}
     */
    private function airbnbCredentialsFromHosts(array $meta, ?string $preferredUserId): array
    {
        $hosts = $this->airbnbHostsFromMeta($meta);
        $preferredUserId = trim((string) $preferredUserId);

        if ($preferredUserId !== '') {
            foreach ($hosts as $host) {
                if ((string) $host['userId'] === $preferredUserId) {
                    return ['userId' => $preferredUserId];
                }
            }
        }

        foreach ($hosts as $host) {
            if (! empty($host['isActive'])) {
                return ['userId' => (string) $host['userId']];
            }
        }

        if ($hosts !== []) {
            return ['userId' => (string) $hosts[0]['userId']];
        }

        return ['userId' => null];
    }

    /**
     * @param  array<string, mixed>  $host
     * @return array<string, mixed>
     */
    private function publicAirbnbHost(array $host): array
    {
        return [
            'userId' => (string) ($host['userId'] ?? ''),
            'isActive' => ! empty($host['isActive']),
            'listingCount' => (int) ($host['listingCount'] ?? count(is_array($host['listings'] ?? null) ? $host['listings'] : [])),
            'capturedAt' => $host['capturedAt'] ?? null,
            'lastError' => $host['lastError'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(BookingPortalConnection $row): array
    {
        $portalKey = $row->portal_key;
        $schema = OtaPortalSchemas::for($portalKey);
        $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
        $stored = is_array($row->external_credentials) ? $row->external_credentials : [];
        $platformBaseUrl = PartnerPortalIntegration::resolveBaseUrl($portalKey);
        $platformEnabled = PartnerPortalIntegration::isEnabled($portalKey);

        $values = [];
        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            $isSecret = ! empty($field['secret']);
            $raw = $stored[$key] ?? null;
            $values[$key] = [
                'hasValue' => filled($raw),
                'value' => $isSecret ? null : ($raw ?: null),
                'masked' => $isSecret && filled($raw) ? $this->maskSecret((string) $raw) : null,
            ];
        }

        $payload = [
            'portalKey' => $portalKey,
            'name' => $schema['name'],
            'description' => $schema['description'],
            'fields' => $schema['fields'],
            'values' => $values,
            'isConnected' => (bool) $row->is_connected,
            'isActive' => (bool) $row->is_active,
            'apiConfigured' => $platformEnabled && filled($platformBaseUrl),
            'platformEnabled' => $platformEnabled,
            'propertyId' => $row->external_organization_uuid,
            'propertyLabel' => $row->external_organization_name,
            'listingCount' => (int) $row->listing_count,
            'lastSync' => $row->last_synced_at?->toIso8601String(),
            'hasSyncIssue' => (bool) $row->has_sync_issue,
            'lastError' => $meta['last_error'] ?? null,
            'lastValidatedAt' => $meta['last_validated_at'] ?? null,
        ];

        if ($portalKey === 'airbnb') {
            $hosts = $this->airbnbHostsFromMeta($meta);
            $payload['hostCount'] = count($hosts);
            $payload['hosts'] = array_map(fn (array $host) => $this->publicAirbnbHost($host), $hosts);
            $payload['description'] = 'Connect one or more Airbnb User IDs, toggle them active/inactive, then map captured listings to your units.';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function normalizeCredentials(string $portalKey, array $input, ?BookingPortalConnection $existing): array
    {
        $schema = OtaPortalSchemas::for($portalKey);
        $existingCreds = is_array($existing?->external_credentials) ? $existing->external_credentials : [];
        $normalized = [];

        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            $isSecret = ! empty($field['secret']);
            $incoming = $input[$key] ?? null;
            $incoming = is_string($incoming) ? trim($incoming) : null;

            if ($incoming === null || $incoming === '') {
                if ($isSecret && filled($existingCreds[$key] ?? null)) {
                    $normalized[$key] = (string) $existingCreds[$key];
                    continue;
                }
                if (! empty($field['required'])) {
                    throw new RuntimeException($field['label'].' is required.');
                }
                $normalized[$key] = null;
                continue;
            }

            $normalized[$key] = $incoming;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|null>  $credentials
     */
    private function propertyId(string $portalKey, array $credentials): ?string
    {
        return match ($portalKey) {
            'booking_com', 'agoda', 'tripadvisor' => $credentials['hotelId'] ?? null,
            'airbnb' => $credentials['userId'] ?? null,
            default => null,
        };
    }

    /**
     * @param  array<string, string|null>  $credentials
     */
    private function propertyLabel(string $portalKey, array $credentials): string
    {
        $name = OtaPortalSchemas::for($portalKey)['name'];
        $id = $this->propertyId($portalKey, $credentials);

        return $id ? $name.' · '.$id : $name;
    }

    /**
     * @param  array<string, string|null>  $credentials
     */
    private function accountIdentifier(string $portalKey, array $credentials): ?string
    {
        return match ($portalKey) {
            'booking_com' => $credentials['clientId'] ?? $credentials['hotelId'] ?? null,
            'airbnb' => $credentials['userId'] ?? null,
            'agoda' => $credentials['siteId'] ?? $credentials['hotelId'] ?? null,
            'tripadvisor' => $credentials['allianceId'] ?? $credentials['hotelId'] ?? null,
            default => null,
        };
    }

    /**
     * @param  array<string, string|null>  $credentials
     */
    private function primarySecret(string $portalKey, array $credentials): ?string
    {
        foreach (OtaPortalSchemas::secretKeys($portalKey) as $key) {
            if (filled($credentials[$key] ?? null)) {
                return (string) $credentials[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $credentials
     * @return array<string, string|null>
     */
    private function publicCredentialSnapshot(string $portalKey, array $credentials): array
    {
        $public = [];
        foreach (OtaPortalSchemas::for($portalKey)['fields'] as $field) {
            if (! empty($field['secret'])) {
                continue;
            }
            $public[$field['key']] = $credentials[$field['key']] ?? null;
        }

        return $public;
    }

    private function maskSecret(string $value): string
    {
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('•', max(4, $len));
        }

        return str_repeat('•', max(4, $len - 4)).substr($value, -4);
    }
}
