<?php

namespace App\Support;

use App\Models\Booking;
use Illuminate\Http\Request;

/**
 * Server-side mobile booking channel detection.
 * Website/direct portal never qualifies as mobile — only a matching app key does.
 */
final class MobileAppClient
{
    public static function bookingSource(Request $request, string $default = Booking::SOURCE_DIRECT_PORTAL): string
    {
        return self::isTrustedMobileRequest($request)
            ? Booking::SOURCE_MOBILE_APP
            : $default;
    }

    public static function isTrustedMobileRequest(Request $request): bool
    {
        $expected = trim((string) config('services.hofros_mobile.app_key', ''));
        if ($expected === '') {
            return false;
        }

        $provided = trim((string) $request->header('X-Hofros-App-Key', ''));
        if ($provided === '') {
            $provided = trim((string) $request->header('X-Ticketoc-App-Key', ''));
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
