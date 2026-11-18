<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;

    public const TYPE_HOTEL = 'hotel';
    public const TYPE_TOUR = 'tour';
    public const BOOKING_MEMBERSHIP = 'membership';
    public const BOOKING_REGULAR = 'regular';

    /** Money, status and inventory fields are written only by booking services, never mass-assigned from input. */
    protected $guarded = ['id'];

    protected $casts = [
        'status' => BookingStatus::class,
        'payment_status' => PaymentStatus::class,
        'check_in' => 'date',
        'check_out' => 'date',
        'nights' => 'integer',
        'rooms_count' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'room_amount' => 'float',
        'membership_nights_used' => 'integer',
        'membership_covered_amount' => 'float',
        'subtotal' => 'float',
        'discount_amount' => 'float',
        'tax_amount' => 'float',
        'service_fee' => 'float',
        'total_amount' => 'float',
        'amount_paid' => 'float',
        'amount_refunded' => 'float',
        'price_breakdown' => 'array',
        'itinerary_snapshot' => 'array',
        'hold_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class)->withTrashed();
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(UserTrip::class, 'user_trip_id')->withTrashed();
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class)->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(BookingGuest::class);
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(BookingCancellation::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->latest('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function membershipTransactions(): HasMany
    {
        return $this->hasMany(MembershipTransaction::class);
    }

    public function isHotel(): bool
    {
        return $this->type === self::TYPE_HOTEL;
    }

    public function isMembershipBooking(): bool
    {
        return $this->booking_type === self::BOOKING_MEMBERSHIP;
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [BookingStatus::Pending, BookingStatus::PaymentPending, BookingStatus::Confirmed], true)
            && $this->check_in && $this->check_in->isAfter(today()->subDay());
    }

    public function amountDue(): float
    {
        return round(max(0, $this->total_amount - $this->amount_paid), 2);
    }
}
