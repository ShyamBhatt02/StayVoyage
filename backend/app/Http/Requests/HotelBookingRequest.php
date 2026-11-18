<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only identifiers, dates, guest details and choices are accepted. Prices, nights, inventory and balances
 * are NEVER read from the request — the backend computes them (expected_total is only used to detect a
 * price change and ask the customer to re-confirm).
 */
class HotelBookingRequest extends FormRequest
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
            'hotel_room_id' => ['required', 'integer', 'exists:hotel_rooms,id'],
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rooms' => ['required', 'integer', 'min:1', 'max:10'],
            'adults' => ['required', 'integer', 'min:1', 'max:30'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booking_type' => ['required', 'in:membership,regular'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'gateway' => ['nullable', 'in:sandbox,razorpay,stripe'],
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            'contact_name' => ['required', 'string', 'min:2', 'max:80'],
            'contact_email' => ['required', 'email:rfc', 'max:150'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?\d{10,15}$/'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'guests' => ['nullable', 'array', 'max:30'],
            'guests.*.full_name' => ['required_with:guests', 'string', 'min:2', 'max:80'],
            'guests.*.guest_type' => ['nullable', 'in:adult,child'],
            'guests.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'guests.*.email' => ['nullable', 'email', 'max:150'],
            'guests.*.phone' => ['nullable', 'string', 'max:20'],
            'accept_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a valid phone number (10–15 digits).',
            'accept_terms.accepted' => 'Please accept the booking terms and cancellation policy.',
            'check_in.after_or_equal' => 'Check-in cannot be in the past.',
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if (is_array($data) && isset($data['special_requests'])) {
            $data['special_requests'] = strip_tags($data['special_requests']);
        }

        return $data;
    }
}
