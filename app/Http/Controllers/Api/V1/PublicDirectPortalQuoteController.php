<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingValidationException;
use App\Http\Controllers\Controller;
use App\Http\Repositories\PublicDirectPortalRepository;
use App\Http\Requests\PublicDirectPortal\PublicQuoteRequest;
use App\Models\Unit;
use App\Support\BookingStayConflict;
use App\Support\DirectPortalPromoCode;
use App\Support\MobileAppClient;
use App\Support\PromotionPricingEngine;
use App\Support\UnitStayPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PublicDirectPortalQuoteController extends Controller
{
    public function __construct(protected PublicDirectPortalRepository $portalRepository)
    {
    }

    public function show(PublicQuoteRequest $request, string $slug): JsonResponse
    {
        $resolved = $this->portalRepository->resolveBySlugOrThrow($slug);
        $user = $resolved['user'];

        $validated = $request->validated();

        $unit = Unit::query()
            ->where('user_uuid', $user->uuid)
            ->whereKey($validated['unitId'])
            ->where('status', 'active')
            ->first();

        if ($unit === null) {
            throw new BookingValidationException('This unit is not available for booking.');
        }

        $checkIn = Carbon::createFromFormat('Y-m-d', $validated['checkIn'])->startOfDay();
        $checkOut = Carbon::createFromFormat('Y-m-d', $validated['checkOut'])->startOfDay();

        $unitCount = (int) ($validated['unitCount'] ?? 1);

        $bookUnits = BookingStayConflict::resolveDirectPortalBookUnits($user->uuid, $unit, $checkIn, $checkOut, $unitCount);
        if (count($bookUnits) < $unitCount) {
            $have = count($bookUnits);
            throw new BookingValidationException($have === 0
                ? BookingStayConflict::guestPortalUnavailableMessage()
                : BookingStayConflict::guestPortalInsufficientUnitsMessage($have, $unitCount));
        }

        $sum = 0.0;
        $currency = null;
        $nights = null;
        foreach ($bookUnits as $bookUnit) {
            $pricing = UnitStayPricing::computeForStay($bookUnit, $checkIn, $checkOut);
            if ($pricing['error'] !== null) {
                throw new BookingValidationException((string) $pricing['error']);
            }
            $sum += (float) $pricing['total'];
            $currency = $pricing['currency'];
            $nights = $pricing['nights'];
        }

        $subtotal = round($sum, 2);
        $bookingSource = MobileAppClient::bookingSource($request);
        $promotion = PromotionPricingEngine::apply(
            $user->uuid,
            $subtotal,
            (int) $nights,
            $checkIn,
            ['bookingSource' => $bookingSource],
        );
        $afterPromotion = (float) $promotion['finalAmount'];

        $promo = DirectPortalPromoCode::resolve(
            $user->uuid,
            $validated['promoCode'] ?? null,
            (int) $nights,
            $afterPromotion,
        );
        if (! $promo['ok']) {
            throw new BookingValidationException((string) $promo['message']);
        }

        $promoDiscount = (float) $promo['discountAmount'];
        $promotionDiscount = (float) $promotion['discountAmount'];
        $totalDiscount = round($promotionDiscount + $promoDiscount, 2);

        return response()->json([
            'subtotalPrice' => $subtotal,
            'promotionDiscountAmount' => $promotionDiscount,
            'promotionName' => $promotion['promotionName'],
            'promotionType' => $promotion['promotionType'],
            'promoCodeDiscountAmount' => $promoDiscount,
            'discountAmount' => $totalDiscount,
            'totalPrice' => (float) $promo['discountedTotal'],
            'currency' => (string) $currency,
            'nights' => (int) $nights,
            'unitCount' => count($bookUnits),
            'promoCode' => $promo['promo']?->code,
            'bookingSource' => $bookingSource,
        ]);
    }
}
