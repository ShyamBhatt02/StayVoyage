<?php

namespace App\Http\Requests\Admin;

use App\Models\Hotel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HotelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $hotel = $this->route('hotel');
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'destination_id' => [$req, 'integer', 'exists:destinations,id'],
            'name' => [$req, 'string', 'min:3', 'max:150'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/', Rule::unique('hotels', 'slug')->ignore($hotel?->id)],
            'property_type' => [$req, Rule::in(Hotel::PROPERTY_TYPES)],
            'star_rating' => [$req, 'integer', 'between:1,5'],
            'short_description' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'address' => [$req, 'string', 'max:255'],
            'city' => [$req, 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => [$req, 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'distance_from_center_km' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'check_in_time' => [$req, 'date_format:H:i'],
            'check_out_time' => [$req, 'date_format:H:i'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_membership_eligible' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive,draft'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'amenity_ids' => ['sometimes', 'array'],
            'amenity_ids.*' => ['integer', 'exists:amenities,id'],
            'policy' => ['sometimes', 'array'],
            'policy.cancellation_policy' => ['nullable', 'string', 'max:3000'],
            'policy.children_policy' => ['nullable', 'string', 'max:3000'],
            'policy.pet_policy' => ['nullable', 'string', 'max:3000'],
            'policy.smoking_policy' => ['nullable', 'string', 'max:3000'],
            'policy.extra_policies' => ['nullable', 'string', 'max:3000'],
            'policy.pets_allowed' => ['nullable', 'boolean'],
            'policy.smoking_allowed' => ['nullable', 'boolean'],
            'partner_ids' => ['sometimes', 'array'],
            'partner_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
