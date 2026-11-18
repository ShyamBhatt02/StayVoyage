<?php

namespace App\Http\Requests;

use App\Enums\TripItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Itinerary item input. No price fields exist here — item prices are always computed server-side. */
class TripItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'item_type' => [$creating ? 'required' : 'prohibited', Rule::enum(TripItemType::class)],
            'itemable_id' => [$creating ? 'nullable' : 'prohibited', 'integer', 'min:1'],
            'trip_day_id' => [$creating ? 'required' : 'nullable', 'integer'],
            'title' => ['nullable', 'string', 'min:2', 'max:120'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'nights' => ['nullable', 'integer', 'min:1', 'max:30'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:12'],
            'meal_plan' => ['nullable', 'in:room_only,breakfast,half_board,full_board'],
            'notes' => ['nullable', 'string', 'max:500'],
            'from_location' => ['nullable', 'string', 'max:120'],
            'to_location' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        foreach (['title', 'notes', 'from_location', 'to_location'] as $f) {
            if (is_array($data) && isset($data[$f])) {
                $data[$f] = strip_tags($data[$f]);
            }
        }

        return $data;
    }
}
