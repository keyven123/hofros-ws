<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePaymentSettingsRequest;
use App\Http\Resources\MerchantPaymentSettingResource;
use App\Models\MerchantPaymentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $row = MerchantPaymentSetting::ensureForUser($request->user());

        return response()->json([
            'payment' => (new MerchantPaymentSettingResource($row))->resolve($request),
        ]);
    }

    public function update(UpdatePaymentSettingsRequest $request): JsonResponse
    {
        $row = MerchantPaymentSetting::ensureForUser($request->user());
        $row->fill($request->toModelPayload());
        $row->save();

        return response()->json([
            'message' => 'Payment settings saved.',
            'payment' => (new MerchantPaymentSettingResource($row->fresh()))->resolve($request),
        ]);
    }
}
