<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Only the guest of a COMPLETED hotel booking can review it, once (DB unique on booking_id). */
    public function create(User $user, Booking $booking, array $data, array $imagePaths = []): Review
    {
        if ($booking->user_id !== $user->id || ! $booking->isHotel() || $booking->status->value !== 'completed') {
            throw DomainException::make('You can review a hotel after completing your stay.', 'review_not_allowed', 403);
        }
        if (Review::withTrashed()->where('booking_id', $booking->id)->exists()) {
            throw DomainException::make('You have already reviewed this stay.', 'review_exists', 409);
        }

        $review = new Review(array_merge($data, ['images' => $imagePaths]));
        $review->user_id = $user->id;
        $review->booking_id = $booking->id;
        $review->hotel_id = $booking->hotel_id;
        $review->forceFill(['status' => 'pending'])->save();

        return $review;
    }

    public function moderate(Review $review, string $status, ?string $note, User $moderator): Review
    {
        return DB::transaction(function () use ($review, $status, $note, $moderator) {
            $old = $review->status;
            $review->forceFill([
                'status' => $status,
                'moderation_note' => $note,
                'moderated_by' => $moderator->id,
                'moderated_at' => now(),
            ])->save();
            $review->hotel?->refreshAggregates();
            $this->audit->log('review.'.$status, $review, ['status' => $old], ['status' => $status], $note);

            return $review;
        });
    }

    public function delete(Review $review, User $moderator): void
    {
        DB::transaction(function () use ($review, $moderator) {
            $hotel = $review->hotel;
            $review->delete();
            $hotel?->refreshAggregates();
            $this->audit->log('review.deleted', $review, ['status' => $review->status], null);
        });
    }
}
