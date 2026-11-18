<?php

namespace App\Http\Requests\Promotion;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePromotionRequest extends FormRequest
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
            'promotionType' => ['required', Rule::in(Promotion::TYPES)],
            'isEnabled' => ['sometimes', 'boolean'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'noEndDate' => ['sometimes', 'boolean'],
            'eligibilityDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'discountType' => ['required', Rule::in(Promotion::DISCOUNT_TYPES)],
            'discountValue' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('promotionType');
            $meta = Promotion::META[$type] ?? null;
            if ($meta === null) {
                return;
            }

            $discountType = (string) $this->input('discountType');
            $discountValue = (float) $this->input('discountValue');
            if ($discountType === Promotion::DISCOUNT_PERCENTAGE && $discountValue > 100) {
                $validator->errors()->add('discountValue', 'Percentage discount cannot exceed 100%.');
            }

            $noEndDate = (bool) $this->boolean('noEndDate');
            $start = $this->input('startDate');
            $end = $this->input('endDate');
            $isEnabled = $this->boolean('isEnabled');

            if (! empty($meta['hasDuration'])) {
                if ($isEnabled && empty($start)) {
                    $validator->errors()->add('startDate', 'Start date is required when enabling this promotion.');
                }
                if (! $noEndDate && ! empty($start) && ! empty($end) && $end < $start) {
                    $validator->errors()->add('endDate', 'End date cannot be earlier than the start date.');
                }
                if (! $noEndDate && $isEnabled && empty($end) && ! empty($start)) {
                    $validator->errors()->add('endDate', 'Provide an end date or select No End Date.');
                }
            }

            if (! empty($meta['hasEligibilityDays'])) {
                $days = $this->input('eligibilityDays');
                if ($days === null || (int) $days < 1) {
                    $validator->errors()->add('eligibilityDays', 'Eligibility days must be greater than zero.');
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelPayload(): array
    {
        $type = (string) $this->input('promotionType');
        $meta = Promotion::META[$type];
        $noEndDate = (bool) $this->boolean('noEndDate');

        $payload = [
            'user_uuid' => $this->user()->uuid,
            'promotion_type' => $type,
            'name' => trim((string) $this->input('name')),
            'is_enabled' => $this->boolean('isEnabled'),
            'discount_type' => $this->input('discountType'),
            'discount_value' => round((float) $this->input('discountValue'), 2),
            'no_end_date' => ! empty($meta['hasDuration']) ? $noEndDate : true,
            'eligibility_days' => ! empty($meta['hasEligibilityDays']) ? (int) $this->input('eligibilityDays') : null,
            'minimum_nights' => $meta['defaultMinimumNights'],
        ];

        if (! empty($meta['hasDuration'])) {
            $payload['start_date'] = $this->input('startDate') ?: null;
            $payload['end_date'] = $noEndDate ? null : ($this->input('endDate') ?: null);
        } else {
            $payload['start_date'] = null;
            $payload['end_date'] = null;
        }

        return $payload;
    }
}
