<?php

namespace App\Services\Ticketoc;

use App\Http\Repositories\BookingPortalRepository;
use App\Models\BookingPortalConnection;
use App\Models\PartnerPortalIntegration;
use Carbon\Carbon;
use RuntimeException;

final class TicketocConnectionService
{
    public function __construct(
        private TicketocClient $client,
        private BookingPortalRepository $portalRepository,
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
    public function test(string $email, string $password): array
    {
        $baseUrl = $this->resolvePlatformBaseUrl();
        $login = $this->client->login($baseUrl, $email, $password);
        $this->client->me($baseUrl, $login['access_token']);
        $stays = $this->client->staysSummary($baseUrl, $login['access_token']);

        return [
            'ok' => true,
            'organizationUuid' => $login['organization_uuid'],
            'organizationName' => $login['organization_name'],
            'listingCount' => $stays['listing_count'],
            'email' => $email,
            'baseUrl' => $baseUrl,
        ];
    }

    /**
     * @return BookingPortalConnection
     */
    public function connect(string $userUuid, string $email, string $password): BookingPortalConnection
    {
        $baseUrl = $this->resolvePlatformBaseUrl();
        $login = $this->client->login($baseUrl, $email, $password);
        $this->client->me($baseUrl, $login['access_token']);
        $stays = $this->client->staysSummary($baseUrl, $login['access_token']);

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
            'listing_count' => max(0, (int) $stays['listing_count']),
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
            $stays = $this->client->staysSummary($baseUrl, $login['access_token']);

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
                'listing_count' => max(0, (int) $stays['listing_count']),
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
