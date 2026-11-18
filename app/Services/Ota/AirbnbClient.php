<?php

namespace App\Services\Ota;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches a host's public Airbnb accommodations via the guest GraphQL profile query
 * (same UserProfileBeehiveListingQuery path used by public host profile pages).
 */
final class AirbnbClient
{
    public const DEFAULT_BASE_URL = 'https://www.airbnb.com';

    /** Well-known public web API key used by Airbnb's own frontend bootstrap. */
    public const FALLBACK_API_KEY = 'd306zoyjsyarp7ifhu67rjxn52tv0t20';

    public const LISTINGS_QUERY_HASH = '529ca816b8be0619618d48b31bf46c379543e297fd68c0a953922927e5497b43';

    public const PAGE_SIZE = 12;

    public const MAX_LISTINGS = 500;

    /**
     * Prefer the guest site GraphQL host. Admin "api.airbnb.com" placeholders are ignored.
     */
    public function resolveBaseUrl(?string $configuredBaseUrl = null): string
    {
        $configured = rtrim(trim((string) $configuredBaseUrl), '/');
        if ($configured === '' || str_contains(strtolower($configured), 'api.airbnb.com')) {
            return self::DEFAULT_BASE_URL;
        }

        return $configured;
    }

    /**
     * @return list<array{id: string, title: string, location: string, label: string, pictureUrl: ?string, roomType: ?string}>
     */
    public function fetchHostListings(string $userId, ?string $configuredBaseUrl = null): array
    {
        $userId = trim($userId);
        if ($userId === '' || ! preg_match('/^\d{5,20}$/', $userId)) {
            throw new RuntimeException('Airbnb User ID must be a numeric host ID.');
        }

        $baseUrl = $this->resolveBaseUrl($configuredBaseUrl);
        $apiKey = $this->resolveApiKey($baseUrl);
        $all = [];
        $offset = 0;

        while (count($all) < self::MAX_LISTINGS) {
            $page = $this->fetchListingsPage((int) $userId, $offset, $apiKey, $baseUrl);
            if ($page === []) {
                break;
            }

            foreach ($page as $row) {
                $all[] = $row;
                if (count($all) >= self::MAX_LISTINGS) {
                    break 2;
                }
            }

            if (count($page) < self::PAGE_SIZE) {
                break;
            }

            $offset += count($page);
        }

        if ($all === []) {
            throw new RuntimeException(
                'No Airbnb accommodations were found for this User ID. Confirm the host profile is public and has active listings.'
            );
        }

        return $all;
    }

    public function resolveApiKey(string $baseUrl): string
    {
        try {
            $response = $this->browser($baseUrl)->get('/');
            if ($response->successful()) {
                $html = $response->body();
                if (preg_match('/"api_config"\s*:\s*\{\s*"key"\s*:\s*"([^"]+)"/', $html, $matches) === 1) {
                    $key = trim($matches[1]);
                    if ($key !== '') {
                        return $key;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to the public web key.
        }

        return self::FALLBACK_API_KEY;
    }

    /**
     * @return list<array{id: string, title: string, location: string, label: string, pictureUrl: ?string, roomType: ?string}>
     */
    private function fetchListingsPage(int $userId, int $offset, string $apiKey, string $baseUrl): array
    {
        $hash = self::LISTINGS_QUERY_HASH;
        $variables = [
            'userId' => $userId,
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
        ];
        $extensions = [
            'persistedQuery' => [
                'version' => 1,
                'sha256Hash' => $hash,
            ],
        ];

        $response = $this->browser($baseUrl)
            ->withHeaders([
                'X-Airbnb-Api-Key' => $apiKey,
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ])
            ->get('/api/v3/UserProfileBeehiveListingQuery/'.$hash, [
                'operationName' => 'UserProfileBeehiveListingQuery',
                'locale' => 'en',
                'currency' => 'USD',
                'variables' => json_encode($variables, JSON_UNESCAPED_SLASHES),
                'extensions' => json_encode($extensions, JSON_UNESCAPED_SLASHES),
            ]);

        if ($response->failed()) {
            $message = (string) ($response->json('error_message') ?? $response->json('error') ?? '');
            throw new RuntimeException(
                $message !== ''
                    ? 'Airbnb listing capture failed: '.$message
                    : 'Airbnb listing capture failed (HTTP '.$response->status().').'
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Airbnb returned an invalid listings response.');
        }

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $first = $payload['errors'][0];
            $message = is_array($first)
                ? (string) ($first['message'] ?? $first['errorMessage'] ?? 'Unknown GraphQL error')
                : 'Unknown GraphQL error';
            throw new RuntimeException('Airbnb listing capture failed: '.$message);
        }

        $rawListings = data_get($payload, 'data.beehive.getListOfListings.listings', []);
        if (! is_array($rawListings)) {
            return [];
        }

        $normalized = [];
        foreach ($rawListings as $item) {
            $listing = $this->normalizeListing($item);
            if ($listing !== null) {
                $normalized[] = $listing;
            }
        }

        return $normalized;
    }

    /**
     * @param  mixed  $item
     * @return array{id: string, title: string, location: string, label: string, pictureUrl: ?string, roomType: ?string}|null
     */
    private function normalizeListing(mixed $item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $listing = is_array($item['listing'] ?? null) ? $item['listing'] : $item;
        $id = trim((string) ($listing['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $title = trim((string) (
            $listing['nameOrPlaceholderName']
            ?? $listing['name']
            ?? $listing['title']
            ?? ''
        ));
        if ($title === '') {
            $title = 'Airbnb listing '.$id;
        }

        $roomType = trim((string) ($listing['localizedRoomType'] ?? $listing['propertyTypeName'] ?? ''));
        $location = trim((string) ($listing['propertyTypeName'] ?? ''));
        if ($location === '' && $roomType !== '') {
            $location = $roomType;
        }

        $pictureUrl = isset($listing['pictureUrl']) && is_string($listing['pictureUrl'])
            ? $listing['pictureUrl']
            : null;

        $label = $id.' | '.$title;
        if ($location !== '') {
            $label .= ' | '.$location;
        }

        return [
            'id' => $id,
            'title' => $title,
            'location' => $location,
            'label' => $label,
            'pictureUrl' => $pictureUrl,
            'roomType' => $roomType !== '' ? $roomType : null,
        ];
    }

    private function browser(string $baseUrl): PendingRequest
    {
        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->timeout(25)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en',
            ]);
    }
}
