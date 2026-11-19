<?php

namespace App\Http\Middleware;

use App\Models\PartnerPortalIntegration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared partner key for the Ticketoc OTA. Ticketoc sends it as Bearer or X-Partner-Key.
 */
class VerifyTicketocPartnerKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) ($request->bearerToken() ?: $request->header('X-Partner-Key', ''));
        $expected = $this->expectedKeys();

        if ($expected === []) {
            return response()->json([
                'message' => 'Ticketoc partner API is not configured. Paste the partner key under Partner integrations.',
            ], 404);
        }

        foreach ($expected as $key) {
            if ($provided !== '' && strlen($key) === strlen($provided) && hash_equals($key, $provided)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Unauthorized.'], 401);
    }

    /**
     * @return list<string>
     */
    private function expectedKeys(): array
    {
        $keys = [];
        $configured = trim((string) config('services.ticketoc.ota_partner_key'));
        if ($configured !== '') {
            $keys[] = $configured;
        }

        try {
            $stored = PartnerPortalIntegration::forPortal('ticketoc')?->storedOtaPartnerKey();
            if (is_string($stored) && $stored !== '') {
                $keys[] = $stored;
            }
        } catch (\Throwable) {
            // Catalog table may not exist yet; the env key is enough.
        }

        return array_values(array_unique($keys));
    }
}
