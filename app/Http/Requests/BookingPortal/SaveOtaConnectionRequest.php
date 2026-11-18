<?php

namespace App\Http\Requests\BookingPortal;

use App\Support\OtaPortalSchemas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveOtaConnectionRequest extends FormRequest
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
        $portalKey = (string) $this->route('portalKey');
        if (! OtaPortalSchemas::isSupported($portalKey)) {
            return [];
        }

        $rules = [];
        foreach (OtaPortalSchemas::for($portalKey)['fields'] as $field) {
            $key = $field['key'];
            $fieldRules = ['nullable', 'string', 'max:255'];
            if (! empty($field['required']) && empty($field['secret'])) {
                $fieldRules = ['required', 'string', 'max:255'];
            }
            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $portalKey = (string) $this->route('portalKey');
            if (! OtaPortalSchemas::isSupported($portalKey)) {
                $validator->errors()->add('portalKey', 'Unsupported OTA portal.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialInput(): array
    {
        $portalKey = (string) $this->route('portalKey');
        $input = [];
        foreach (OtaPortalSchemas::for($portalKey)['fields'] as $field) {
            $key = $field['key'];
            if ($this->exists($key)) {
                $input[$key] = $this->input($key);
            }
        }

        return $input;
    }
}
