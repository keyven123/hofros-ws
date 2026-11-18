<?php

namespace App\Http\Requests\PaymentGateway;

use App\Models\MerchantPaymentGateway;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantPaymentGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'environment' => ['nullable', Rule::in([MerchantPaymentGateway::ENV_TESTING, MerchantPaymentGateway::ENV_PRODUCTION])],
            'publicKey' => ['nullable', 'string', 'min:8'],
            'secretKey' => ['nullable', 'string', 'min:8'],
            'apiUsername' => ['nullable', 'string'],
            'apiPassword' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelPayload(MerchantPaymentGateway $gateway): array
    {
        $payload = [
            'name' => trim((string) $this->input('name')),
        ];

        if ($this->filled('environment')) {
            $payload['environment'] = (string) $this->input('environment');
        }

        if ($this->filled('publicKey')) {
            $payload['public_key'] = trim((string) $this->input('publicKey'));
        }
        if ($this->filled('secretKey')) {
            $payload['secret_key'] = trim((string) $this->input('secretKey'));
        }
        if ($gateway->provider === MerchantPaymentGateway::PROVIDER_PAYNAMICS) {
            if ($this->filled('apiUsername')) {
                $payload['api_username'] = trim((string) $this->input('apiUsername'));
            }
            if ($this->filled('apiPassword')) {
                $payload['api_password'] = trim((string) $this->input('apiPassword'));
            }
        }

        if ($gateway->provider === MerchantPaymentGateway::PROVIDER_PAYMONGO) {
            $public = $payload['public_key'] ?? $gateway->public_key;
            $secret = $payload['secret_key'] ?? $gateway->secret_key;
            $blob = strtolower((string) $public.' '.(string) $secret);
            $payload['environment'] = str_contains($blob, 'live')
                ? MerchantPaymentGateway::ENV_PRODUCTION
                : MerchantPaymentGateway::ENV_TESTING;
        }

        return $payload;
    }
}
