<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HotelSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination' => ['nullable', 'string', 'max:100'],
            'destination_id' => ['nullable', 'integer'],
            'check_in' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'required_with:check_out'],
            'check_out' => ['nullable', 'date_format:Y-m-d', 'after:check_in', 'required_with:check_in'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:30'],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:10'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'rating_min' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'stars' => ['nullable', 'array'],
            'stars.*' => ['integer', 'between:1,5'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:50'],
            'property_types' => ['nullable', 'array'],
            'property_types.*' => ['string', 'max:30'],
            'distance_max' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'membership' => ['nullable', 'boolean'],
            'breakfast' => ['nullable', 'boolean'],
            'free_cancellation' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:recommended,price_asc,price_desc,rating,distance'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:6', 'max:48'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_in.after_or_equal' => 'Check-in cannot be in the past.',
            'check_out.after' => 'Check-out must be after check-in.',
        ];
    }
}
