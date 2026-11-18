<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function moderate(User $user, Review $review): bool
    {
        return $user->hasPermission('reviews.moderate');
    }

    public function update(User $user, Review $review): bool
    {
        return $review->user_id === $user->id && $review->status === 'pending';
    }
}
