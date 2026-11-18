<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingValidationException;
use App\Exceptions\NoPromotionFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\StorePromotionRequest;
use App\Http\Requests\Promotion\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\BookingPromotion;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Promotion::query()
            ->forUser($request->user()->uuid)
            ->orderByDesc('created_at')
            ->get();

        $stats = [
            'activePromotions' => $rows->filter(fn (Promotion $p) => $p->status() === 'active')->count(),
            'enabledPromotions' => $rows->where('is_enabled', true)->count(),
            'bookingsWithPromotion' => BookingPromotion::query()
                ->whereHas('booking', fn ($q) => $q->where('user_uuid', $request->user()->uuid))
                ->count(),
            'totalDiscountGiven' => round((float) BookingPromotion::query()
                ->whereHas('booking', fn ($q) => $q->where('user_uuid', $request->user()->uuid))
                ->sum('discount_amount'), 2),
        ];

        return response()->json([
            'promotions' => PromotionResource::collection($rows)->resolve($request),
            'promotionTypes' => collect(Promotion::TYPES)->map(fn (string $type) => [
                'id' => $type,
                'name' => Promotion::META[$type]['name'],
                'description' => Promotion::META[$type]['description'],
                'hasDuration' => Promotion::META[$type]['hasDuration'],
                'hasEligibilityDays' => Promotion::META[$type]['hasEligibilityDays'],
                'eligibilityLabel' => Promotion::META[$type]['eligibilityLabel'],
                'fixedDiscountScope' => Promotion::META[$type]['fixedDiscountScope'],
                'defaultMinimumNights' => Promotion::META[$type]['defaultMinimumNights'],
            ])->values(),
            'stats' => $stats,
        ]);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promo = Promotion::query()->create($request->toModelPayload());

        return response()->json([
            'promotion' => (new PromotionResource($promo->fresh()))->resolve($request),
            'message' => 'Promotion created.',
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePromotionRequest $request, string $id): JsonResponse
    {
        $promo = $this->ownedOrThrow($id, $request->user()->uuid);
        $promo->fill($request->toModelPayload($promo->promotion_type));
        $promo->save();

        return response()->json([
            'promotion' => (new PromotionResource($promo->fresh()))->resolve($request),
            'message' => 'Promotion updated.',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $promo = $this->ownedOrThrow($id, $request->user()->uuid);
        $promo->delete();

        return response()->json(['message' => 'Promotion deleted.']);
    }

    public function toggle(Request $request, string $id): JsonResponse
    {
        $promo = $this->ownedOrThrow($id, $request->user()->uuid);

        $enable = ! $promo->is_enabled;
        if ($enable) {
            $meta = $promo->meta();
            if ((float) $promo->discount_value <= 0) {
                throw new BookingValidationException('Configure a discount value before enabling this promotion.');
            }
            if (! empty($meta['hasDuration']) && $promo->start_date === null) {
                throw new BookingValidationException('Set a start date before enabling this promotion.');
            }
            if (! empty($meta['hasEligibilityDays']) && (int) $promo->eligibility_days < 1) {
                throw new BookingValidationException('Set eligibility days before enabling this promotion.');
            }
        }

        $promo->is_enabled = $enable;
        $promo->save();

        return response()->json([
            'promotion' => (new PromotionResource($promo->fresh()))->resolve($request),
            'message' => $enable ? 'Promotion enabled.' : 'Promotion disabled.',
        ]);
    }

    private function ownedOrThrow(string $id, string $userUuid): Promotion
    {
        $promo = Promotion::query()->forUser($userUuid)->whereKey($id)->first();
        if ($promo === null) {
            throw new NoPromotionFoundException();
        }

        return $promo;
    }
}
