<?php

namespace App\Http\Requests\PaymentGateway;

use App\Models\MerchantPaymentGateway;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMerchantPaymentGatewayRequest extends FormRequest
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
        $provider = (string) $this->input('provider');

        return [
            'provider' => ['required', Rule::in(MerchantPaymentGateway::PROVIDERS)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'environment' => ['nullable', Rule::in([MerchantPaymentGateway::ENV_TESTING, MerchantPaymentGateway::ENV_PRODUCTION])],
            'publicKey' => ['required', 'string', 'min:8'],
            'secretKey' => ['required', 'string', 'min:8'],
            'apiUsername' => [$provider === MerchantPaymentGateway::PROVIDER_PAYNAMICS ? 'required' : 'nullable', 'string'],
            'apiPassword' => [$provider === MerchantPaymentGateway::PROVIDER_PAYNAMICS ? 'required' : 'nullable', 'string'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelPayload(): array
    {
        $provider = (string) $this->input('provider');
        $environment = (string) ($this->input('environment') ?: MerchantPaymentGateway::ENV_TESTING);

        if ($provider === MerchantPaymentGateway::PROVIDER_PAYMONGO) {
            $blob = strtolower((string) $this->input('publicKey').' '.(string) $this->input('secretKey'));
            $environment = str_contains($blob, 'live')
                ? MerchantPaymentGateway::ENV_PRODUCTION
                : MerchantPaymentGateway::ENV_TESTING;
        }

        return [
            'user_uuid' => $this->user()->uuid,
            'provider' => $provider,
            'name' => trim((string) $this->input('name')),
            'environment' => $environment,
            'public_key' => trim((string) $this->input('publicKey')),
            'secret_key' => trim((string) $this->input('secretKey')),
            'api_username' => $provider === MerchantPaymentGateway::PROVIDER_PAYNAMICS ? trim((string) $this->input('apiUsername')) : null,
            'api_password' => $provider === MerchantPaymentGateway::PROVIDER_PAYNAMICS ? trim((string) $this->input('apiPassword')) : null,
            'status' => $this->boolean('activate')
                ? MerchantPaymentGateway::STATUS_ACTIVE
                : MerchantPaymentGateway::STATUS_INACTIVE,
        ];
    }
}
