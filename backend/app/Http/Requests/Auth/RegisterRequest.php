<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->email)),
            'name' => trim(strip_tags((string) $this->name)),
            'phone' => preg_replace('/[^\d+]/', '', (string) $this->phone),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\s.\'-]+$/u'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^\+?\d{10,15}$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Please use letters only for your name.',
            'phone.regex' => 'Enter a valid mobile number (10–15 digits, optional +country code).',
            'email.unique' => 'An account with this email already exists. Try logging in instead.',
            'accept_terms.accepted' => 'Please accept the terms and privacy policy to continue.',
        ];
    }
}
