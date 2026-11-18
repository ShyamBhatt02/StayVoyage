<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'status', 'moderated_by', 'moderated_at', 'moderation_note'];

    protected $casts = [
        'images' => 'array',
        'overall' => 'integer',
        'cleanliness' => 'integer',
        'location' => 'integer',
        'service' => 'integer',
        'value' => 'integer',
        'moderated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function getImageUrlsAttribute(): array
    {
        return array_values(array_filter(array_map([Media::class, 'url'], $this->images ?? [])));
    }
}
