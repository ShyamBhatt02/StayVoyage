<?php

namespace App\Services\Membership;

use App\Enums\MembershipStatus;
use App\Exceptions\DomainException;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Membership;
use App\Models\MembershipBlackoutDate;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Validates whether a membership may be used for a specific stay. Rules come from the plan snapshot
 * frozen at purchase (customer's deal can't change underneath them); blackout dates are operational and
 * read live from the plan.
 */
class MembershipRulesService
{
    /** Is the hotel/room membership-eligible at all (ignoring the customer's membership)? */
    public function isRoomEligible(Hotel $hotel, HotelRoom $room): bool
    {
        return $hotel->is_membership_eligible && $room->is_membership_eligible;
    }

    public function requireUsableMembership(User $user): Membership
    {
        $membership = $user->memberships()
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Expired->value])
            ->latest('expires_at')
            ->first();

        if (! $membership) {
            throw DomainException::make('You need an active StayVoyage membership to book with membership nights.', 'membership_required', 422);
        }
        if (! $membership->isUsable()) {
            throw DomainException::make(
                'Your membership expired on '.$membership->expires_at?->format('j M Y').'. Renew it to keep using membership nights.',
                'membership_expired',
                422,
            );
        }

        return $membership;
    }

    /**
     * @return int membership nights required (nights x rooms)
     * @throws DomainException on any rule violation
     */
    public function assertBookable(Membership $membership, Hotel $hotel, HotelRoom $room, string $checkIn, string $checkOut, int $rooms, int $guests): int
    {
        if (! $membership->isUsable()) {
            throw DomainException::make('Your membership is not active, so it cannot be used for this booking.', 'membership_expired');
        }
        if (! $this->isRoomEligible($hotel, $room)) {
            throw DomainException::make('This room is not eligible for membership booking. You can still book it as a regular paid booking.', 'membership_not_eligible');
        }

        $in = CarbonImmutable::parse($checkIn)->startOfDay();
        $out = CarbonImmutable::parse($checkOut)->startOfDay();
        $nights = (int) $in->diffInDays($out);
        $needed = $nights * $rooms;

        if (! $membership->rule('all_hotels_eligible', true)) {
            $allowed = $membership->plan?->hotels()->whereKey($hotel->id)->exists();
            if (! $allowed) {
                throw DomainException::make('Your membership plan does not include this hotel.', 'membership_not_eligible');
            }
        }

        $types = $membership->rule('eligible_room_types');
        if (is_array($types) && $types && ! in_array($room->room_type, $types, true)) {
            throw DomainException::make('Your membership covers '.implode(', ', array_map('ucfirst', $types)).' rooms only.', 'membership_not_eligible');
        }

        $maxNights = (int) $membership->rule('max_nights_per_booking', 7);
        if ($nights > $maxNights) {
            throw DomainException::make("Membership bookings are limited to {$maxNights} nights per booking.", 'membership_rule_violation', 422, ['max_nights' => $maxNights]);
        }
        $maxRooms = (int) $membership->rule('max_rooms_per_booking', 1);
        if ($rooms > $maxRooms) {
            throw DomainException::make("Membership bookings are limited to {$maxRooms} room(s) per booking.", 'membership_rule_violation', 422, ['max_rooms' => $maxRooms]);
        }
        $maxGuests = (int) $membership->rule('max_guests_per_booking', 2);
        if ($guests > $maxGuests) {
            throw DomainException::make("Membership bookings allow up to {$maxGuests} guests.", 'membership_rule_violation', 422, ['max_guests' => $maxGuests]);
        }

        $today = CarbonImmutable::today();
        $minAdvance = (int) $membership->rule('min_advance_booking_days', 0);
        if ($in->lt($today->addDays($minAdvance))) {
            throw DomainException::make("Membership stays must be booked at least {$minAdvance} day(s) in advance.", 'membership_rule_violation');
        }
        $maxAdvance = (int) $membership->rule('max_advance_booking_days', 90);
        if ($in->gt($today->addDays($maxAdvance))) {
            throw DomainException::make("Membership stays can be booked up to {$maxAdvance} days ahead.", 'membership_rule_violation');
        }

        if (! $membership->rule('nights_extend_past_expiry', false)) {
            $lastNight = $out->subDay();
            if ($lastNight->gt(CarbonImmutable::parse($membership->expires_at)->startOfDay())) {
                throw DomainException::make(
                    'Your membership expires on '.$membership->expires_at->format('j M Y').' — all membership nights must fall before that date.',
                    'membership_rule_violation',
                );
            }
        }

        $blackout = MembershipBlackoutDate::query()
            ->where('membership_plan_id', $membership->membership_plan_id)
            ->where('start_date', '<', $out->toDateString())
            ->where('end_date', '>=', $in->toDateString())
            ->orderBy('start_date')
            ->first();
        if ($blackout) {
            throw DomainException::make(
                'Membership nights cannot be used between '.$blackout->start_date->format('j M').' and '.$blackout->end_date->format('j M Y').($blackout->reason ? " ({$blackout->reason})" : '').'.',
                'membership_blackout',
            );
        }

        if ($membership->nights_balance < $needed) {
            throw DomainException::make(
                "You have {$membership->nights_balance} membership night(s) remaining but this stay needs {$needed}.",
                'insufficient_membership_nights',
                422,
                ['available' => $membership->nights_balance, 'required' => $needed],
            );
        }

        return $needed;
    }
}
