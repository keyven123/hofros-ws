<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'acceptCash' => ['required', 'boolean'],
            'acceptGcash' => ['required', 'boolean'],
            'acceptBankTransfer' => ['required', 'boolean'],
            'acceptCard' => ['required', 'boolean'],
            'gcashName' => ['nullable', 'string', 'max:255'],
            'gcashNumber' => ['nullable', 'string', 'max:30'],
            'bankName' => ['nullable', 'string', 'max:255'],
            'bankAccountName' => ['nullable', 'string', 'max:255'],
            'bankAccountNumber' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', 'string', 'max:8'],
            'depositRequired' => ['required', 'boolean'],
            'depositPercent' => ['required', 'integer', 'min:0', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelPayload(): array
    {
        $validated = $this->validated();

        return [
            'accept_cash' => $validated['acceptCash'],
            'accept_gcash' => $validated['acceptGcash'],
            'accept_bank_transfer' => $validated['acceptBankTransfer'],
            'accept_card' => $validated['acceptCard'],
            'gcash_name' => $validated['gcashName'] ?? null,
            'gcash_number' => $validated['gcashNumber'] ?? null,
            'bank_name' => $validated['bankName'] ?? null,
            'bank_account_name' => $validated['bankAccountName'] ?? null,
            'bank_account_number' => $validated['bankAccountNumber'] ?? null,
            'currency' => strtoupper($validated['currency']),
            'deposit_required' => $validated['depositRequired'],
            'deposit_percent' => $validated['depositPercent'],
            'instructions' => $validated['instructions'] ?? null,
        ];
    }
}
