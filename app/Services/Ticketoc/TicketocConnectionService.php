<?php

namespace App\Services\Ticketoc;

use App\Http\Repositories\BookingPortalRepository;
use App\Models\BookingPortalConnection;
use App\Models\PartnerPortalIntegration;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TicketocConnectionService
{
    public function __construct(
        private TicketocClient $client,
        private BookingPortalRepository $portalRepository,
        private HofrosRoomCatalog $rooms,
        private TicketocStayConnectionService $stay,
    ) {
    }

    public function resolvePlatformBaseUrl(): string
    {
        if (! PartnerPortalIntegration::isEnabled(BookingPortalRepository::TICKETOC_KEY)) {
            throw new RuntimeException('Ticketoc partner integration is disabled by the platform admin.');
        }

        $baseUrl = PartnerPortalIntegration::resolveBaseUrl(BookingPortalRepository::TICKETOC_KEY);
        if ($baseUrl === null || $baseUrl === '') {
            throw new RuntimeException('Ticketoc API base URL is not configured. Ask a Hofros admin to set it under Partner integrations.');
        }

        return $baseUrl;
    }

    /**
     * @return array{ok: bool, organizationUuid: ?string, organizationName: ?string, listingCount: int, email: string, baseUrl: string}
     */
    public function test(string $userUuid, string $email, string $password): array
    {
        $this->assertStayReady();
        $baseUrl = $this->resolvePlatformBaseUrl();
        $login = $this->client->login($baseUrl, $email, $password);
        $this->client->me($baseUrl, $login['access_token']);
        if (! filled($login['organization_uuid'])) {
            throw new RuntimeException('This Ticketoc account is not linked to an organization.');
        }

        return [
            'ok' => true,
            'organizationUuid' => $login['organization_uuid'],
            'organizationName' => $login['organization_name'],
            'listingCount' => $this->roomCount($userUuid),
            'email' => $email,
            'baseUrl' => $baseUrl,
        ];
    }

    /**
     * @return BookingPortalConnection
     */
    public function connect(string $userUuid, string $email, string $password): BookingPortalConnection
    {
        $this->assertStayReady();
        $baseUrl = $this->resolvePlatformBaseUrl();
        $login = $this->client->login($baseUrl, $email, $password);
        $this->client->me($baseUrl, $login['access_token']);
        if (! filled($login['organization_uuid'])) {
            throw new RuntimeException('This Ticketoc account is not linked to an organization.');
        }
        $captured = $this->pushRooms($userUuid, (string) $login['organization_uuid']);

        $row = $this->portalRepository->ticketocRow($userUuid);

        $expiresAt = null;
        if (! empty($login['expires_in'])) {
            try {
                $expiresAt = Carbon::parse((string) $login['expires_in']);
            } catch (\Throwable) {
                $expiresAt = now()->addHours(12);
            }
        }

        $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
        $meta['environment'] = str_contains(strtolower($baseUrl), 'localhost') || str_contains(strtolower($baseUrl), '127.0.0.1')
            ? 'testing'
            : 'production';
        $meta['last_validated_at'] = now()->toIso8601String();
        $meta['last_error'] = null;

        $row->update([
            'is_connected' => true,
            'is_active' => true,
            'listing_count' => $captured['rooms'],
            'last_synced_at' => now(),
            'has_sync_issue' => false,
            'external_base_url' => $baseUrl,
            'external_merchant_email' => $email,
            'external_api_secret' => $password,
            'external_access_token' => $login['access_token'],
            'external_token_expires_at' => $expiresAt,
            'external_organization_uuid' => $login['organization_uuid'],
            'external_organization_name' => $login['organization_name'],
            'connection_meta' => $meta,
        ]);

        return $row->fresh();
    }

    public function disconnect(string $userUuid): BookingPortalConnection
    {
        $row = $this->portalRepository->ticketocRow($userUuid);

        $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
        $meta['last_error'] = null;
        $meta['disconnected_at'] = now()->toIso8601String();

        $row->update([
            'is_connected' => false,
            'is_active' => false,
            'listing_count' => 0,
            'has_sync_issue' => false,
            'external_base_url' => null,
            'external_merchant_email' => null,
            'external_api_secret' => null,
            'external_access_token' => null,
            'external_token_expires_at' => null,
            'external_organization_uuid' => null,
            'external_organization_name' => null,
            'connection_meta' => $meta,
        ]);

        return $row->fresh();
    }

    public function sync(string $userUuid): BookingPortalConnection
    {
        $row = $this->portalRepository->ticketocRow($userUuid);
        if (! $row->is_connected) {
            throw new RuntimeException('Connect Ticketoc before syncing.');
        }

        $baseUrl = $this->resolvePlatformBaseUrl();
        $email = (string) $row->external_merchant_email;
        $password = (string) $row->external_api_secret;
        if ($email === '' || $password === '') {
            throw new RuntimeException('Ticketoc credentials are incomplete. Reconnect the integration.');
        }

        try {
            $login = $this->client->login($baseUrl, $email, $password);
            $organizationUuid = (string) ($login['organization_uuid'] ?: $row->external_organization_uuid);
            if ($organizationUuid === '') {
                throw new RuntimeException('This Ticketoc account is not linked to an organization.');
            }
            $captured = $this->pushRooms($userUuid, $organizationUuid);

            $expiresAt = null;
            if (! empty($login['expires_in'])) {
                try {
                    $expiresAt = Carbon::parse((string) $login['expires_in']);
                } catch (\Throwable) {
                    $expiresAt = now()->addHours(12);
                }
            }

            $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
            $meta['last_validated_at'] = now()->toIso8601String();
            $meta['last_error'] = null;

            $row->update([
                'listing_count' => $captured['rooms'],
                'last_synced_at' => now(),
                'has_sync_issue' => false,
                'external_base_url' => $baseUrl,
                'external_access_token' => $login['access_token'],
                'external_token_expires_at' => $expiresAt,
                'external_organization_uuid' => $login['organization_uuid'] ?: $row->external_organization_uuid,
                'external_organization_name' => $login['organization_name'] ?: $row->external_organization_name,
                'connection_meta' => $meta,
            ]);
        } catch (RuntimeException $e) {
            $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
            $meta['last_error'] = $e->getMessage();
            $row->update([
                'has_sync_issue' => true,
                'connection_meta' => $meta,
            ]);
            throw $e;
        }

        return $row->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(BookingPortalConnection $row): array
    {
        $meta = is_array($row->connection_meta) ? $row->connection_meta : [];
        $platformBaseUrl = PartnerPortalIntegration::resolveBaseUrl(BookingPortalRepository::TICKETOC_KEY);
        $platformEnabled = PartnerPortalIntegration::isEnabled(BookingPortalRepository::TICKETOC_KEY);

        return [
            'portalKey' => BookingPortalRepository::TICKETOC_KEY,
            'isConnected' => (bool) $row->is_connected,
            'isActive' => (bool) $row->is_active,
            'apiConfigured' => $platformEnabled && filled($platformBaseUrl),
            'platformEnabled' => $platformEnabled,
            'stayConnected' => filled(PartnerPortalIntegration::forPortal(BookingPortalRepository::TICKETOC_KEY)?->otaPartnerKey()),
            'email' => $row->external_merchant_email,
            'emailMasked' => $this->maskEmail($row->external_merchant_email),
            'hasPassword' => filled($row->external_api_secret),
            'organizationUuid' => $row->external_organization_uuid,
            'organizationName' => $row->external_organization_name,
            'listingCount' => (int) $row->listing_count,
            'lastSync' => $row->last_synced_at?->toIso8601String(),
            'hasSyncIssue' => (bool) $row->has_sync_issue,
            'lastError' => $meta['last_error'] ?? null,
            'environment' => $meta['environment'] ?? null,
            'lastValidatedAt' => $meta['last_validated_at'] ?? null,
        ];
    }

    private function assertStayReady(): void
    {
        $key = PartnerPortalIntegration::forPortal(BookingPortalRepository::TICKETOC_KEY)?->otaPartnerKey();
        if (! filled($key)) {
            throw new RuntimeException('Ticketoc Stay is not connected yet. Ask a Hofros admin to connect Stay under Partner integrations.');
        }
    }

    private function roomCount(string $userUuid): int
    {
        $count = 0;
        foreach ($this->rooms->forUser($userUuid) as $property) {
            $count += count(is_array($property['units'] ?? null) ? $property['units'] : []);
        }

        return $count;
    }

    /**
     * @return array{properties: int, rooms: int}
     */
    private function pushRooms(string $userUuid, string $organizationUuid): array
    {
        $this->assertStayReady();
        $key = (string) PartnerPortalIntegration::forPortal(BookingPortalRepository::TICKETOC_KEY)?->otaPartnerKey();
        $properties = $this->rooms->forUser($userUuid);
        if ($properties === []) {
            throw new RuntimeException('This Hofros account has no rooms to capture.');
        }

        foreach ($properties as &$property) {
            $property['organization_uuid'] = $organizationUuid;
        }
        unset($property);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->withToken($key)
                ->withHeaders(['X-Partner-Key' => $key])
                ->put($this->stay->inventoryUrl($this->resolvePlatformBaseUrl()), [
                    'properties' => $properties,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Ticketoc Stay could not be reached.');
        }

        if ($response->status() === 409) {
            throw new RuntimeException('Ticketoc Stay is still disconnected. Ask a Hofros admin to click Connect Stay, then try again.');
        }

        if ($response->status() === 401) {
            throw new RuntimeException('Ticketoc rejected the Stay partner key. Ask a Hofros admin to save the same key and connect again.');
        }

        if ($response->failed()) {
            $message = trim((string) ($response->json('message') ?: 'Ticketoc did not accept the rooms.'));
            throw new RuntimeException($message);
        }

        $result = is_array($response->json('result')) ? $response->json('result') : [];

        return [
            'properties' => (int) ($result['properties'] ?? count($properties)),
            'rooms' => (int) ($result['rooms'] ?? $this->roomCount($userUuid)),
        ];
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '' || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $keep = min(2, strlen($local));

        return substr($local, 0, $keep).str_repeat('•', max(1, strlen($local) - $keep)).'@'.$domain;
    }
}
