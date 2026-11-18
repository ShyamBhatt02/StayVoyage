<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TourBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'contact_email' => strtolower(trim((string) $this->contact_email)),
            'contact_phone' => preg_replace('/[^\d+]/', '', (string) $this->contact_phone),
            'coupon_code' => $this->coupon_code ? strtoupper(trim((string) $this->coupon_code)) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'contact_name' => ['required', 'string', 'min:2', 'max:80'],
            'contact_email' => ['required', 'email:rfc', 'max:150'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?\d{10,15}$/'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'gateway' => ['nullable', 'in:sandbox,razorpay,stripe'],
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            'travelers' => ['required', 'array', 'min:1', 'max:30'],
            'travelers.*.full_name' => ['required', 'string', 'min:2', 'max:80'],
            'travelers.*.traveler_type' => ['required', 'in:adult,child'],
            'travelers.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'travelers.*.gender' => ['nullable', 'in:male,female,other,prefer_not'],
            'travelers.*.nationality' => ['nullable', 'string', 'max:60'],
            'travelers.*.email' => ['nullable', 'email', 'max:150'],
            'travelers.*.phone' => ['nullable', 'string', 'max:20'],
            'accept_terms' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $trip = $this->route('trip');
            if (! $trip || $v->errors()->isNotEmpty()) {
                return;
            }
            $travelers = collect($this->input('travelers', []));
            if ($travelers->count() !== $trip->travelerCount()) {
                $v->errors()->add('travelers', "Please add details for all {$trip->travelerCount()} travellers on this trip.");
            }
            if ($travelers->where('traveler_type', 'child')->count() !== (int) $trip->children) {
                $v->errors()->add('travelers', "This trip has {$trip->children} child traveller(s) — please mark them as children.");
            }
        }];
    }

    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a valid phone number (10–15 digits).',
            'accept_terms.accepted' => 'Please accept the booking terms and cancellation policy.',
        ];
    }
}
