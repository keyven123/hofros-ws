<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantPaymentSettingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'acceptCash' => (bool) $this->accept_cash,
            'acceptGcash' => (bool) $this->accept_gcash,
            'acceptBankTransfer' => (bool) $this->accept_bank_transfer,
            'acceptCard' => (bool) $this->accept_card,
            'gcashName' => $this->gcash_name ?? '',
            'gcashNumber' => $this->gcash_number ?? '',
            'bankName' => $this->bank_name ?? '',
            'bankAccountName' => $this->bank_account_name ?? '',
            'bankAccountNumber' => $this->bank_account_number ?? '',
            'currency' => $this->currency ?: 'PHP',
            'depositRequired' => (bool) $this->deposit_required,
            'depositPercent' => (int) $this->deposit_percent,
            'instructions' => $this->instructions ?? '',
        ];
    }
}
