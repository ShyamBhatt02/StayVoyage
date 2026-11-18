<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Booking;
use App\Services\ImageUploadService;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, string $number, ReviewService $reviews, ImageUploadService $images): JsonResponse
    {
        $booking = Booking::query()->where('booking_number', $number)->firstOrFail();
        $this->authorize('review', $booking);

        $data = $request->validate([
            'overall' => ['required', 'integer', 'between:1,5'],
            'cleanliness' => ['required', 'integer', 'between:1,5'],
            'location' => ['required', 'integer', 'between:1,5'],
            'service' => ['required', 'integer', 'between:1,5'],
            'value' => ['required', 'integer', 'between:1,5'],
            'title' => ['required', 'string', 'min:4', 'max:150'],
            'comment' => ['required', 'string', 'min:20', 'max:3000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=300,min_height=200'],
        ]);
        $paths = [];
        foreach ($request->file('images', []) as $file) {
            $paths[] = $images->store($file, 'reviews', 1600);
        }
        $data['title'] = strip_tags($data['title']);
        $data['comment'] = strip_tags($data['comment']);
        unset($data['images']);

        $review = $reviews->create($request->user(), $booking, $data, $paths);

        return $this->created(new ReviewResource($review->load('hotel')), 'Thanks for your review! It will appear once our team has checked it (usually within 24 hours).');
    }

    public function mine(Request $request): JsonResponse
    {
        $reviews = $request->user()->reviews()->with(['hotel', 'booking'])->latest()->paginate(10);

        return $this->ok(ReviewResource::collection($reviews));
    }
}
