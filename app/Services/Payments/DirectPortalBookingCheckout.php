<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\BookingCheckout;
use App\Models\BookingPayment;
use App\Models\MerchantPaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DirectPortalBookingCheckout
{
    public const MODE_FULL = 'full';

    public const MODE_RESERVATION = 'reservation';

    public function __construct(private MerchantGatewayCheckoutService $gateways)
    {
    }

    /**
     * After booking create: signal that the guest should pick Pay now / Pay reservation.
     *
     * @param  list<Booking>  $bookings
     * @return array{checkout: null, checkoutUrl: null, paymentRequired: bool, provider: ?string, providerLabel: ?string, totalAmount: float, reservationAmount: float, currency: string}
     */
    public function prepareForBookings(array $bookings, float $amount, string $currency): array
    {
        if ($bookings === []) {
            return [
                'checkout' => null,
                'checkoutUrl' => null,
                'paymentRequired' => false,
                'provider' => null,
                'providerLabel' => null,
                'totalAmount' => 0.0,
                'reservationAmount' => 0.0,
                'currency' => $currency ?: 'PHP',
            ];
        }

        $first = $bookings[0];
        $total = round(max(0.0, $amount), 2);
        $gateway = MerchantPaymentGateway::activeForUser($first->user_uuid);

        // If the merchant has an online gateway, send the guest to the checkout page
        // (Pay now / Pay reservation) even when the amount still needs review.
        if ($gateway === null) {
            return [
                'checkout' => null,
                'checkoutUrl' => null,
                'paymentRequired' => false,
                'provider' => null,
                'providerLabel' => null,
                'totalAmount' => $total,
                'reservationAmount' => round($total / 2, 2),
                'currency' => $currency ?: 'PHP',
            ];
        }

        return [
            'checkout' => null,
            'checkoutUrl' => null,
            'paymentRequired' => true,
            'provider' => $gateway->provider,
            'providerLabel' => $this->providerLabel($gateway->provider),
            'totalAmount' => $total,
            'reservationAmount' => round($total / 2, 2),
            'currency' => $currency ?: 'PHP',
        ];
    }

    /**
     * @return array{checkout: BookingCheckout, checkoutUrl: string, paymentRequired: bool, provider: string, providerLabel: string, paymentMode: string, amount: float, totalAmount: float, reservationAmount: float}
     */
    public function startForBatch(string $merchantSlug, string $userUuid, string $portalBatchId, string $mode): array
    {
        $mode = $mode === self::MODE_RESERVATION ? self::MODE_RESERVATION : self::MODE_FULL;

        $bookings = Booking::query()
            ->where('user_uuid', $userUuid)
            ->where('portal_batch_id', $portalBatchId)
            ->orderBy('created_at')
            ->get();

        if ($bookings->isEmpty()) {
            throw new RuntimeException('Booking checkout not found.');
        }

        $gateway = MerchantPaymentGateway::activeForUser($userUuid);
        if ($gateway === null) {
            throw new RuntimeException('No online payment gateway is configured for this host.');
        }

        $existingPaid = BookingCheckout::query()
            ->where('user_uuid', $userUuid)
            ->where('portal_batch_id', $portalBatchId)
            ->where('status', BookingCheckout::STATUS_PAID)
            ->exists();

        if ($existingPaid) {
            throw new RuntimeException('This booking has already been paid.');
        }

        // Cancel any unfinished pending sessions for this batch so the guest can switch modes.
        BookingCheckout::query()
            ->where('user_uuid', $userUuid)
            ->where('portal_batch_id', $portalBatchId)
            ->where('status', BookingCheckout::STATUS_PENDING)
            ->update(['status' => BookingCheckout::STATUS_CANCELLED]);

        $totalAmount = round((float) $bookings->sum(static fn (Booking $b): float => (float) $b->total_price), 2);
        if ($totalAmount <= 0) {
            throw new RuntimeException('There is no amount to pay.');
        }

        $payAmount = $mode === self::MODE_RESERVATION
            ? round($totalAmount / 2, 2)
            : $totalAmount;

        if ($payAmount < 0.01) {
            throw new RuntimeException('There is no amount to pay.');
        }

        $first = $bookings->first();
        $currency = (string) ($first->currency ?? 'PHP');
        $frontend = rtrim((string) config('services.paymongo.frontend_url', config('app.frontend_url', 'http://localhost:3000')), '/');

        $checkout = BookingCheckout::query()->create([
            'user_uuid' => $userUuid,
            'payment_gateway_uuid' => $gateway->uuid,
            'provider' => $gateway->provider,
            'portal_batch_id' => $portalBatchId,
            'booking_uuid' => $first->uuid,
            'reference' => 'HFR-PAY-'.strtoupper(Str::random(10)),
            'amount' => $payAmount,
            'currency' => $currency,
            'status' => BookingCheckout::STATUS_PENDING,
            'metadata' => [
                'booking_uuids' => $bookings->pluck('uuid')->values()->all(),
                'merchant_slug' => $merchantSlug,
                'payment_mode' => $mode,
                'total_amount' => $totalAmount,
                'reservation_amount' => round($totalAmount / 2, 2),
            ],
        ]);

        $successUrl = $frontend.'/'.$merchantSlug.'/directportal/payment/return?ref='.$checkout->uuid;
        $cancelUrl = $frontend.'/'.$merchantSlug.'/directportal/payment/return?ref='.$checkout->uuid.'&cancelled=1';

        $description = $mode === self::MODE_RESERVATION
            ? 'Hofros reservation (50%) '.$first->reference
            : 'Hofros booking '.$first->reference;

        try {
            $session = $this->gateways->start(
                $gateway,
                $checkout,
                $description,
                $successUrl,
                $cancelUrl,
            );
        } catch (RuntimeException $e) {
            $checkout->update(['status' => BookingCheckout::STATUS_FAILED]);
            throw $e;
        }

        if (($session['checkout_url'] ?? '') === '') {
            $checkout->update(['status' => BookingCheckout::STATUS_FAILED]);
            throw new RuntimeException('The payment gateway did not return a checkout URL.');
        }

        $checkout->update([
            'provider_session_id' => $session['id'],
            'checkout_url' => $session['checkout_url'],
        ]);

        return [
            'checkout' => $checkout->fresh(),
            'checkoutUrl' => $session['checkout_url'],
            'paymentRequired' => true,
            'provider' => $gateway->provider,
            'providerLabel' => $this->providerLabel($gateway->provider),
            'paymentMode' => $mode,
            'amount' => $payAmount,
            'totalAmount' => $totalAmount,
            'reservationAmount' => round($totalAmount / 2, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForBatch(string $userUuid, string $portalBatchId): array
    {
        $bookings = Booking::query()
            ->where('user_uuid', $userUuid)
            ->where('portal_batch_id', $portalBatchId)
            ->orderBy('created_at')
            ->get();

        if ($bookings->isEmpty()) {
            throw new RuntimeException('Booking checkout not found.');
        }

        $first = $bookings->first();
        $gateway = MerchantPaymentGateway::activeForUser($userUuid);
        $totalAmount = round((float) $bookings->sum(static fn (Booking $b): float => (float) $b->total_price), 2);
        $paid = BookingCheckout::query()
            ->where('user_uuid', $userUuid)
            ->where('portal_batch_id', $portalBatchId)
            ->where('status', BookingCheckout::STATUS_PAID)
            ->latest('paid_at')
            ->first();

        return [
            'portalBatchId' => $portalBatchId,
            'reference' => $first->reference,
            'references' => $bookings->pluck('reference')->values()->all(),
            'guestName' => $first->guest_name,
            'guestEmail' => $first->guest_email,
            'guestPhone' => $first->guest_phone,
            'checkIn' => optional($first->check_in)?->toDateString(),
            'checkOut' => optional($first->check_out)?->toDateString(),
            'adults' => (int) $first->adults,
            'children' => (int) $first->children,
            'unitCount' => $bookings->count(),
            'currency' => (string) ($first->currency ?? 'PHP'),
            'totalAmount' => $totalAmount,
            'reservationAmount' => round($totalAmount / 2, 2),
            'status' => $first->status,
            'alreadyPaid' => $paid !== null,
            'paidAmount' => $paid ? (float) $paid->amount : null,
            'paidMode' => is_array($paid?->metadata) ? ($paid->metadata['payment_mode'] ?? null) : null,
            'paymentGateway' => $gateway ? [
                'provider' => $gateway->provider,
                'providerLabel' => $this->providerLabel($gateway->provider),
                'name' => $gateway->name,
                'onlineCheckout' => true,
            ] : null,
        ];
    }

    public function confirm(string $checkoutUuid, bool $cancelled = false): BookingCheckout
    {
        $checkout = BookingCheckout::query()->whereKey($checkoutUuid)->first();
        if ($checkout === null) {
            throw new RuntimeException('Checkout not found.');
        }

        if ($cancelled) {
            if ($checkout->status === BookingCheckout::STATUS_PENDING) {
                $checkout->update(['status' => BookingCheckout::STATUS_CANCELLED]);
            }

            return $checkout->fresh();
        }

        if ($checkout->status === BookingCheckout::STATUS_PAID) {
            return $checkout;
        }

        $gateway = MerchantPaymentGateway::query()->whereKey($checkout->payment_gateway_uuid)->first();
        if ($gateway === null) {
            throw new RuntimeException('Payment gateway is no longer available.');
        }

        if (! $this->gateways->isPaid($gateway, $checkout)) {
            throw new RuntimeException('Payment is not completed yet.');
        }

        DB::transaction(function () use ($checkout): void {
            $checkout->update([
                'status' => BookingCheckout::STATUS_PAID,
                'paid_at' => now(),
            ]);

            $bookingIds = is_array($checkout->metadata['booking_uuids'] ?? null)
                ? $checkout->metadata['booking_uuids']
                : array_filter([$checkout->booking_uuid]);

            $mode = (string) ($checkout->metadata['payment_mode'] ?? self::MODE_FULL);
            $bookings = Booking::query()->whereIn('uuid', $bookingIds)->get();
            $remaining = (float) $checkout->amount;
            $count = $bookings->count();

            foreach ($bookings->values() as $index => $booking) {
                $share = $index === $count - 1
                    ? $remaining
                    : round(((float) $checkout->amount) / max(1, $count), 2);
                $share = min($remaining, max(0.0, $share));
                $remaining = round(max(0.0, $remaining - $share), 2);

                $modeLabel = $mode === self::MODE_RESERVATION ? 'reservation (50%)' : 'full payment';

                BookingPayment::query()->create([
                    'booking_uuid' => $booking->uuid,
                    'user_uuid' => $checkout->user_uuid,
                    'amount' => $share,
                    'currency' => $checkout->currency,
                    'payment_type' => $checkout->provider === 'paynamics'
                        ? BookingPayment::TYPE_OTHER
                        : BookingPayment::TYPE_CARD,
                    'transaction_kind' => BookingPayment::KIND_PAYMENT,
                    'notes' => 'Direct website '.$modeLabel.' '.$checkout->reference.' via '.$checkout->provider,
                ]);

                if ($booking->status === Booking::STATUS_PENDING) {
                    $booking->update(['status' => Booking::STATUS_ACCEPTED]);
                }
            }
        });

        return $checkout->fresh();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            MerchantPaymentGateway::PROVIDER_PAYMONGO => 'PayMongo',
            MerchantPaymentGateway::PROVIDER_PAYPAL => 'PayPal',
            MerchantPaymentGateway::PROVIDER_PAYNAMICS => 'Paynamics',
            default => $provider,
        };
    }
}
