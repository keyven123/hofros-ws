<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingValidationException;
use App\Http\Controllers\Controller;
use App\Http\Repositories\PublicDirectPortalRepository;
use App\Models\BookingCheckout;
use App\Models\MerchantPaymentGateway;
use App\Services\Payments\DirectPortalBookingCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PublicDirectPortalPaymentController extends Controller
{
    public function __construct(
        protected PublicDirectPortalRepository $portalRepository,
        protected DirectPortalBookingCheckout $bookingCheckout,
    ) {
    }

    public function showCheckout(Request $request, string $slug, string $batchId): JsonResponse
    {
        $resolved = $this->portalRepository->resolveBySlugOrThrow($slug);

        try {
            $summary = $this->bookingCheckout->summaryForBatch($resolved['user']->uuid, $batchId);
        } catch (RuntimeException $e) {
            throw new BookingValidationException($e->getMessage());
        }

        return response()->json($summary);
    }

    public function startCheckout(Request $request, string $slug, string $batchId): JsonResponse
    {
        $resolved = $this->portalRepository->resolveBySlugOrThrow($slug);
        $mode = (string) $request->input('mode', DirectPortalBookingCheckout::MODE_FULL);

        if (! in_array($mode, [DirectPortalBookingCheckout::MODE_FULL, DirectPortalBookingCheckout::MODE_RESERVATION], true)) {
            throw new BookingValidationException('Choose pay now or pay reservation.');
        }

        try {
            $started = $this->bookingCheckout->startForBatch(
                $slug,
                $resolved['user']->uuid,
                $batchId,
                $mode,
            );
        } catch (RuntimeException $e) {
            throw new BookingValidationException($e->getMessage());
        }

        return response()->json([
            'checkoutId' => $started['checkout']->uuid,
            'checkoutUrl' => $started['checkoutUrl'],
            'paymentMode' => $started['paymentMode'],
            'amount' => $started['amount'],
            'totalAmount' => $started['totalAmount'],
            'reservationAmount' => $started['reservationAmount'],
            'provider' => $started['provider'],
            'providerLabel' => $started['providerLabel'],
            'currency' => $started['checkout']->currency,
        ]);
    }

    public function show(Request $request, string $slug, string $ref): JsonResponse
    {
        $resolved = $this->portalRepository->resolveBySlugOrThrow($slug);
        $checkout = BookingCheckout::query()
            ->whereKey($ref)
            ->where('user_uuid', $resolved['user']->uuid)
            ->first();

        if ($checkout === null) {
            throw new BookingValidationException('Checkout not found.');
        }

        return response()->json($this->payload($checkout));
    }

    public function confirm(Request $request, string $slug): JsonResponse
    {
        $resolved = $this->portalRepository->resolveBySlugOrThrow($slug);
        $ref = (string) $request->input('ref', '');
        $cancelled = (bool) $request->boolean('cancelled');

        $checkout = BookingCheckout::query()
            ->whereKey($ref)
            ->where('user_uuid', $resolved['user']->uuid)
            ->first();

        if ($checkout === null) {
            throw new BookingValidationException('Checkout not found.');
        }

        try {
            $checkout = $this->bookingCheckout->confirm($checkout->uuid, $cancelled);
        } catch (RuntimeException $e) {
            throw new BookingValidationException($e->getMessage());
        }

        return response()->json($this->payload($checkout));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BookingCheckout $checkout): array
    {
        $meta = is_array($checkout->metadata) ? $checkout->metadata : [];

        return [
            'id' => $checkout->uuid,
            'reference' => $checkout->reference,
            'status' => $checkout->status,
            'amount' => (float) $checkout->amount,
            'currency' => $checkout->currency,
            'provider' => $checkout->provider,
            'providerLabel' => match ($checkout->provider) {
                MerchantPaymentGateway::PROVIDER_PAYMONGO => 'PayMongo',
                MerchantPaymentGateway::PROVIDER_PAYPAL => 'PayPal',
                MerchantPaymentGateway::PROVIDER_PAYNAMICS => 'Paynamics',
                default => $checkout->provider,
            },
            'paymentMode' => $meta['payment_mode'] ?? null,
            'totalAmount' => isset($meta['total_amount']) ? (float) $meta['total_amount'] : (float) $checkout->amount,
            'checkoutUrl' => $checkout->checkout_url,
            'paidAt' => $checkout->paid_at?->toIso8601String(),
        ];
    }
}
