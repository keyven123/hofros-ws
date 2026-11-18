<?php

namespace App\Services\Ticketoc;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TicketocClient
{
    /**
     * @return array{access_token: string, expires_in: ?string, admin_user: array<string, mixed>, organization_uuid: ?string, organization_name: ?string}
     */
    public function login(string $baseUrl, string $email, string $password): array
    {
        $response = $this->http($baseUrl)->post('/admin/login', [
            'email' => $email,
            'password' => $password,
        ]);

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? $response->json('error') ?? '');
            throw new RuntimeException($message !== '' ? $message : 'Ticketoc login failed. Check the API URL and credentials.');
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('Ticketoc did not return an access token.');
        }

        $adminUser = is_array($response->json('admin_user')) ? $response->json('admin_user') : [];
        $organization = is_array($adminUser['organization'] ?? null) ? $adminUser['organization'] : [];

        return [
            'access_token' => $token,
            'expires_in' => $response->json('expires_in'),
            'admin_user' => $adminUser,
            'organization_uuid' => isset($adminUser['organization_uuid'])
                ? (string) $adminUser['organization_uuid']
                : (isset($organization['uuid']) ? (string) $organization['uuid'] : null),
            'organization_name' => isset($organization['name']) ? (string) $organization['name'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(string $baseUrl, string $accessToken): array
    {
        $response = $this->http($baseUrl, $accessToken)->get('/admin/me');

        if ($response->failed()) {
            throw new RuntimeException('Ticketoc session validation failed.');
        }

        $payload = $response->json();
        if (is_array($payload['data'] ?? null)) {
            return $payload['data'];
        }
        if (is_array($payload['admin_user'] ?? null)) {
            return $payload['admin_user'];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array{listing_count: int, raw: array<string, mixed>}
     */
    public function staysSummary(string $baseUrl, string $accessToken): array
    {
        $stats = $this->http($baseUrl, $accessToken)->get('/stays/stats');
        if ($stats->successful()) {
            $payload = $stats->json();
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : (is_array($payload) ? $payload : []);
            $count = (int) ($data['total'] ?? $data['total_stays'] ?? $data['count'] ?? $data['listings'] ?? 0);
            if ($count > 0) {
                return ['listing_count' => $count, 'raw' => $data];
            }
        }

        $index = $this->http($baseUrl, $accessToken)->get('/stays', [
            'per_page' => 1,
            'page' => 1,
        ]);

        if ($index->failed()) {
            throw new RuntimeException('Could not load stays from Ticketoc. Ensure this account can view stays.');
        }

        $payload = $index->json();
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $count = (int) ($meta['total'] ?? (is_countable($data) ? count($data) : 0));

        return [
            'listing_count' => max(0, $count),
            'raw' => is_array($payload) ? $payload : [],
        ];
    }

    private function http(string $baseUrl, ?string $accessToken = null): PendingRequest
    {
        $request = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->timeout(20)
            ->asJson();

        if ($accessToken !== null && $accessToken !== '') {
            $request = $request->withToken($accessToken);
        }

        return $request;
    }
}
