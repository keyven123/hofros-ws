<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentGateway\StoreMerchantPaymentGatewayRequest;
use App\Http\Requests\PaymentGateway\UpdateMerchantPaymentGatewayRequest;
use App\Http\Resources\MerchantPaymentGatewayResource;
use App\Models\MerchantPaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MerchantPaymentGatewayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = MerchantPaymentGateway::query()
            ->forUser($request->user()->uuid)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'gateways' => MerchantPaymentGatewayResource::collection($rows)->resolve($request),
            'providers' => [
                [
                    'id' => MerchantPaymentGateway::PROVIDER_PAYMONGO,
                    'label' => 'PayMongo',
                    'publicKeyLabel' => 'Public Key',
                    'secretKeyLabel' => 'Secret Key',
                    'requiresApiCredentials' => false,
                ],
                [
                    'id' => MerchantPaymentGateway::PROVIDER_PAYPAL,
                    'label' => 'PayPal',
                    'publicKeyLabel' => 'Client ID',
                    'secretKeyLabel' => 'Client Secret',
                    'requiresApiCredentials' => false,
                ],
                [
                    'id' => MerchantPaymentGateway::PROVIDER_PAYNAMICS,
                    'label' => 'Paynamics',
                    'publicKeyLabel' => 'Merchant ID',
                    'secretKeyLabel' => 'Merchant Key',
                    'requiresApiCredentials' => true,
                ],
            ],
            'activeGatewayId' => $rows->firstWhere('status', MerchantPaymentGateway::STATUS_ACTIVE)?->uuid,
        ]);
    }

    public function store(StoreMerchantPaymentGatewayRequest $request): JsonResponse
    {
        $payload = $request->toModelPayload();

        $gateway = DB::transaction(function () use ($payload) {
            if ($payload['status'] === MerchantPaymentGateway::STATUS_ACTIVE) {
                MerchantPaymentGateway::query()
                    ->forUser($payload['user_uuid'])
                    ->where('status', MerchantPaymentGateway::STATUS_ACTIVE)
                    ->update(['status' => MerchantPaymentGateway::STATUS_INACTIVE]);
            }

            return MerchantPaymentGateway::query()->create($payload);
        });

        return response()->json([
            'message' => 'Payment gateway added.',
            'gateway' => (new MerchantPaymentGatewayResource($gateway->fresh()))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateMerchantPaymentGatewayRequest $request, string $id): JsonResponse
    {
        $gateway = $this->ownedOrThrow($id, $request->user()->uuid);
        $gateway->fill($request->toModelPayload($gateway))->save();

        return response()->json([
            'message' => 'Payment gateway updated.',
            'gateway' => (new MerchantPaymentGatewayResource($gateway->fresh()))->resolve($request),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $gateway = $this->ownedOrThrow($id, $request->user()->uuid);
        $gateway->delete();

        return response()->json(['message' => 'Payment gateway removed.']);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        $gateway = $this->ownedOrThrow($id, $request->user()->uuid);

        DB::transaction(function () use ($gateway, $request): void {
            MerchantPaymentGateway::query()
                ->forUser($request->user()->uuid)
                ->where('uuid', '!=', $gateway->uuid)
                ->where('status', MerchantPaymentGateway::STATUS_ACTIVE)
                ->update(['status' => MerchantPaymentGateway::STATUS_INACTIVE]);

            $gateway->status = MerchantPaymentGateway::STATUS_ACTIVE;
            $gateway->save();
        });

        return response()->json([
            'message' => 'This gateway is now active for the direct website checkout.',
            'gateway' => (new MerchantPaymentGatewayResource($gateway->fresh()))->resolve($request),
        ]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $gateway = $this->ownedOrThrow($id, $request->user()->uuid);
        $gateway->status = MerchantPaymentGateway::STATUS_INACTIVE;
        $gateway->save();

        return response()->json([
            'message' => 'Payment gateway set to offline.',
            'gateway' => (new MerchantPaymentGatewayResource($gateway->fresh()))->resolve($request),
        ]);
    }

    private function ownedOrThrow(string $id, string $userUuid): MerchantPaymentGateway
    {
        $gateway = MerchantPaymentGateway::query()->forUser($userUuid)->whereKey($id)->first();
        if ($gateway === null) {
            throw new BookingValidationException('Payment gateway not found.');
        }

        return $gateway;
    }
}
