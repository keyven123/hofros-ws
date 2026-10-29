<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayMongoCheckoutService
{
    /**
     * @param  list<array{name: string, amount: float, quantity: int}>  $lines
     * @return array{id: string, checkout_url: string}
     */
    public function create(string $reference, float $amount, string $currency, array $lines, string $description): array
    {
        $secret = trim((string) config('services.paymongo.secret_key'));
        if ($secret === '') {
            throw new RuntimeException('PayMongo is not configured.');
        }

        $frontend = rtrim((string) config('services.paymongo.frontend_url'), '/');
        $lineItems = [];
        foreach ($lines as $line) {
            $centavos = (int) round($line['amount'] * 100);
            if ($centavos < 1) {
                continue;
            }
            $lineItems[] = [
                'currency' => $currency,
                'amount' => $centavos,
                'name' => $line['name'],
                'quantity' => max(1, (int) $line['quantity']),
            ];
        }

        if ($lineItems === []) {
            throw new RuntimeException('There is no amount to pay.');
        }

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->post('https://api.paymongo.com/v1/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'line_items' => $lineItems,
                        'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay', 'qrph'],
                        'success_url' => $frontend.'/billing/checkout/return?ref='.$reference,
                        'cancel_url' => $frontend.'/billing/checkout?cancelled=1',
                        'description' => $description,
                        'reference_number' => $reference,
                        'statement_descriptor' => 'HOFROS',
                        'metadata' => [
                            'reference' => $reference,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('PayMongo could not start the checkout.');
        }

        $data = $response->json('data');

        return [
            'id' => (string) ($data['id'] ?? ''),
            'checkout_url' => (string) ($data['attributes']['checkout_url'] ?? ''),
        ];
    }

    public function isPaid(string $checkoutSessionId): bool
    {
        $secret = trim((string) config('services.paymongo.secret_key'));
        if ($secret === '' || $checkoutSessionId === '') {
            return false;
        }

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->get('https://api.paymongo.com/v1/checkout_sessions/'.$checkoutSessionId);

        if ($response->failed()) {
            return false;
        }

        $attributes = $response->json('data.attributes') ?? [];
        $payments = $attributes['payments'] ?? [];
        foreach ($payments as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') {
                return true;
            }
        }

        $intent = $attributes['payment_intent']['attributes']['status'] ?? '';

        return $intent === 'succeeded';
    }
}
