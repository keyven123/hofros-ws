<?php

namespace App\Http\Requests\BookingPortal;

use Illuminate\Foundation\Http\FormRequest;

class SaveTicketocConnectionRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => trim((string) $this->input('email')),
            'password' => (string) $this->input('password'),
        ];
    }
}
