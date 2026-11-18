<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingValidationException;
use App\Http\Controllers\Controller;
use App\Http\Repositories\BookingRepository;
use App\Http\Repositories\PublicDirectPortalRepository;
use App\Http\Requests\PublicDirectPortal\PublicCreateBookingRequest;
use App\Models\Booking;
use App\Models\BookingPromotion;
use App\Models\PromoCode;
use App\Models\Unit;
use App\Support\BookingStayConflict;
use App\Support\DirectPortalPromoCode;
use App\Support\MobileAppClient;
use App\Support\PromotionPricingEngine;
use App\Support\UnitStayPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicDirectPortalBookingController extends Controller
{
    public function __construct(
        protected PublicDirectPortalRepository $portalRepository,
        protected BookingRepository $bookingRepository,
    ) {
    }

    public function store(PublicCreateBookingRequest $request, string $slug): JsonResponse
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

        $adults = (int) $validated['adults'];
        $children = (int) $validated['children'];

        $digits = preg_replace('/\D+/', '', (string) $validated['guestPhone']) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw new BookingValidationException('Please enter a valid mobile number (8–15 digits).');
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

        foreach ($bookUnits as $bookUnit) {
            $maxGuests = (int) $bookUnit->max_guests;
            if ($adults + $children > $maxGuests) {
                throw new BookingValidationException('Guest count exceeds the maximum for this unit.');
            }
        }

        $pricedRows = [];
        $subtotal = 0.0;
        $nights = null;
        foreach ($bookUnits as $bookUnit) {
            $pricing = UnitStayPricing::computeForStay($bookUnit, $checkIn, $checkOut);
            if ($pricing['error'] !== null) {
                throw new BookingValidationException((string) $pricing['error']);
            }
            $subtotal += (float) $pricing['total'];
            $nights = $pricing['nights'];
            $pricedRows[] = [
                'unit' => $bookUnit,
                'total' => $pricing['total'],
            ];
        }

        $bookingSource = MobileAppClient::bookingSource($request);
        $promotion = PromotionPricingEngine::apply(
            $user->uuid,
            round($subtotal, 2),
            (int) $nights,
            $checkIn,
            ['bookingSource' => $bookingSource],
        );
        $promotionDiscount = (float) $promotion['discountAmount'];
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
        $discountAmount = round($promotionDiscount + $promoDiscount, 2);
        $promoEntity = $promo['promo'];
        $selectedPromotion = $promotion['promotion'];

        if ($discountAmount > 0.0 && $subtotal > 0.0) {
            $remaining = $discountAmount;
            $count = count($pricedRows);
            foreach ($pricedRows as $idx => &$row) {
                $raw = (float) $row['total'];
                $share = $idx === $count - 1
                    ? $remaining
                    : round($discountAmount * ($raw / $subtotal), 2);
                $share = min($remaining, max(0.0, $share));
                $row['original'] = $raw;
                $row['discountShare'] = $share;
                $row['total'] = round(max(0.0, $raw - $share), 2);
                $remaining = round(max(0.0, $remaining - $share), 2);
            }
            unset($row);
            if ($remaining > 0.0 && $count > 0) {
                $last = $count - 1;
                $pricedRows[$last]['discountShare'] = round((float) ($pricedRows[$last]['discountShare'] ?? 0) + $remaining, 2);
                $pricedRows[$last]['total'] = round(max(0.0, (float) $pricedRows[$last]['total'] - $remaining), 2);
            }
        } else {
            foreach ($pricedRows as &$row) {
                $row['original'] = (float) $row['total'];
                $row['discountShare'] = 0.0;
            }
            unset($row);
        }

        $batchId = (string) Str::uuid();
        $multi = count($bookUnits) > 1;

        try {
            $created = DB::transaction(function () use (
                $user,
                $pricedRows,
                $checkIn,
                $checkOut,
                $adults,
                $children,
                $validated,
                $batchId,
                $multi,
                $promoEntity,
                $selectedPromotion,
                $promotion,
                $bookingSource,
            ): array {
                $out = [];
                foreach ($pricedRows as $row) {
                    $bookUnit = $row['unit'];
                    $total = $row['total'];
                    $reference = $this->bookingRepository->uniqueReference();

                    $booking = $this->bookingRepository->create([
                        'user_uuid' => $user->uuid,
                        'unit_uuid' => $bookUnit->uuid,
                        'reference' => $reference,
                        'guest_name' => $validated['guestName'],
                        'guest_email' => $validated['guestEmail'],
                        'guest_phone' => $validated['guestPhone'],
                        'check_in' => $checkIn->toDateString(),
                        'check_out' => $checkOut->toDateString(),
                        'adults' => $adults,
                        'children' => $children,
                        'total_price' => $total,
                        'currency' => $bookUnit->currency,
                        'source' => $bookingSource,
                        'status' => Booking::STATUS_PENDING,
                        'notes' => null,
                        'portal_batch_id' => $multi ? $batchId : null,
                    ]);

                    if ($selectedPromotion !== null && (float) $promotion['discountAmount'] > 0) {
                        $original = round((float) ($row['original'] ?? $total), 2);
                        $baseTotal = max(0.01, (float) array_sum(array_map(
                            static fn (array $r): float => (float) ($r['original'] ?? 0),
                            $pricedRows
                        )));
                        $unitPromotionShare = round(((float) $promotion['discountAmount']) * ($original / $baseTotal), 2);
                        $unitPromotionShare = min($original, max(0.0, $unitPromotionShare));

                        BookingPromotion::query()->create([
                            'booking_uuid' => $booking->uuid,
                            'promotion_uuid' => $selectedPromotion->uuid,
                            'promotion_type' => $selectedPromotion->promotion_type,
                            'promotion_name' => $selectedPromotion->displayName(),
                            'original_amount' => $original,
                            'discount_amount' => $unitPromotionShare,
                            'final_amount' => round(max(0.0, $original - $unitPromotionShare), 2),
                            'discount_type' => $selectedPromotion->discount_type,
                            'discount_value' => $selectedPromotion->discount_value,
                            'selection_reason' => $promotion['selectionReason'],
                        ]);
                    }

                    $out[] = $booking;
                }

                if ($promoEntity !== null) {
                    $updated = PromoCode::query()
                        ->where('uuid', $promoEntity->uuid)
                        ->where('user_uuid', $user->uuid)
                        ->where('status', PromoCode::STATUS_ACTIVE)
                        ->where(static function ($q): void {
                            $q->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses');
                        })
                        ->update(['uses_count' => DB::raw('uses_count + 1')]);
                    if ($updated < 1) {
                        throw new BookingValidationException('Promo code has reached its usage limit.');
                    }
                }

                return $out;
            });
        } catch (BookingValidationException $e) {
            throw $e;
        }

        return response()->json($this->toPublicPayload(
            $created,
            round($subtotal, 2),
            $discountAmount,
            $promoEntity?->code,
            $promotionDiscount,
            $promotion['promotionName'],
            $promoDiscount,
        ), Response::HTTP_CREATED);
    }

    /**
     * @param  list<Booking>  $bookings
     * @return array<string, mixed>
     */
    private function toPublicPayload(
        array $bookings,
        float $subtotal,
        float $discountAmount,
        ?string $promoCode,
        float $promotionDiscount = 0.0,
        ?string $promotionName = null,
        float $promoCodeDiscount = 0.0,
    ): array {
        $first = $bookings[0];
        $refs = array_map(static fn (Booking $b): string => (string) $b->reference, $bookings);
        $totalAmount = round(array_sum(array_map(static fn (Booking $b): float => (float) $b->total_price, $bookings)), 2);

        return [
            'reference' => $first->reference,
            'references' => $refs,
            'unitCount' => count($bookings),
            'subtotalAmount' => round($subtotal, 2),
            'promotionDiscountAmount' => round($promotionDiscount, 2),
            'promotionName' => $promotionName,
            'promoCodeDiscountAmount' => round($promoCodeDiscount, 2),
            'discountAmount' => round($discountAmount, 2),
            'totalAmount' => $totalAmount,
            'promoCode' => $promoCode,
            'portalBatchId' => $first->portal_batch_id,
            'status' => $first->status,
            'message' => count($bookings) > 1
                ? 'Your booking requests were recorded. The host will follow up to confirm.'
                : 'Your booking request was recorded. The host will follow up to confirm.',
        ];
    }
}
