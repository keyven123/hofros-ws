<?php

namespace App\Services\Ticketoc;

use App\Models\PartnerPortalIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Hofros admin connects Ticketoc Stay by storing the partner key Ticketoc generated,
 * then calling Ticketoc's Stay ping with that key.
 */
class TicketocStayConnectionService
{
    /**
     * @return array{status: string, message: string}
     */
    public function connect(PartnerPortalIntegration $row): array
    {
        $key = $row->otaPartnerKey();
        if ($key === null) {
            throw new RuntimeException('Paste the partner key from Ticketoc Settings → Integration → Stay.');
        }

        $base = PartnerPortalIntegration::resolveBaseUrl('ticketoc');
        if ($base === null) {
            throw new RuntimeException('Set the Ticketoc API base URL before connecting Stay.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(12)
                ->withToken($key)
                ->withHeaders(['X-Partner-Key' => $key])
                ->get($this->pingUrl($base));
        } catch (ConnectionException) {
            $row->markStay('error', 'Ticketoc Stay could not be reached.');
            throw new RuntimeException('Ticketoc Stay could not be reached. Check the API base URL.');
        }

        if ($response->successful() && $response->json('ok') === true) {
            $row->markStay('connected', null);

            return [
                'status' => 'connected',
                'message' => 'Ticketoc Stay accepted the partner key. Hofros rooms can be sold on Ticketoc.',
            ];
        }

        if ($response->status() === 409) {
            $row->markStay('ready', null);

            return [
                'status' => 'ready',
                'message' => 'Partner key is saved on Hofros. On Ticketoc, open this integration and click Connect.',
            ];
        }

        if ($response->status() === 401) {
            $row->markStay('error', 'Ticketoc rejected the partner key.');
            throw new RuntimeException('Ticketoc rejected the partner key. Paste the key generated for this integration.');
        }

        $message = trim((string) ($response->json('message') ?: 'Ticketoc Stay did not accept the connection.'));
        $row->markStay('error', $message);

        throw new RuntimeException($message);
    }

    public function pingUrl(string $base): string
    {
        $base = rtrim(trim($base), '/');
        if (preg_match('#/api/v1$#', $base) === 1) {
            return $base.'/integrations/stay/ping';
        }
        if (preg_match('#/api$#', $base) === 1) {
            return $base.'/v1/integrations/stay/ping';
        }

        return $base.'/api/v1/integrations/stay/ping';
    }
}
