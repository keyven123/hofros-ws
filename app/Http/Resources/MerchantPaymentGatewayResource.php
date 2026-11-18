<?php

namespace App\Http\Resources;

use App\Models\MerchantPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantPaymentGatewayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MerchantPaymentGateway $gateway */
        $gateway = $this->resource;

        return [
            'id' => $gateway->uuid,
            'provider' => $gateway->provider,
            'providerLabel' => match ($gateway->provider) {
                MerchantPaymentGateway::PROVIDER_PAYMONGO => 'PayMongo',
                MerchantPaymentGateway::PROVIDER_PAYPAL => 'PayPal',
                MerchantPaymentGateway::PROVIDER_PAYNAMICS => 'Paynamics',
                default => $gateway->provider,
            },
            'name' => $gateway->name,
            'environment' => $gateway->detectEnvironment(),
            'status' => $gateway->status,
            'isOnline' => $gateway->status === MerchantPaymentGateway::STATUS_ACTIVE,
            'publicKeyMasked' => MerchantPaymentGateway::maskCredential($gateway->public_key, $gateway->provider === MerchantPaymentGateway::PROVIDER_PAYMONGO ? 8 : 0),
            'secretKeyMasked' => MerchantPaymentGateway::maskCredential($gateway->secret_key),
            'hasApiCredentials' => filled($gateway->api_username) && filled($gateway->api_password),
            'updatedAt' => $gateway->updated_at?->toIso8601String(),
            'createdAt' => $gateway->created_at?->toIso8601String(),
        ];
    }
}
