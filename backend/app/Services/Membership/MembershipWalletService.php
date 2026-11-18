<?php

namespace App\Services\Membership;

use App\Enums\MembershipTransactionType as Type;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\MembershipTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY place membership night balances change.
 *
 * Guarantees:
 *  - every change is a ledger row (balance_before / balance_after) written in the same DB transaction
 *    as the cached `memberships.nights_balance` update, under a SELECT ... FOR UPDATE row lock;
 *  - balances can never go negative (checked here, and by a DB CHECK constraint);
 *  - each booking can be deducted once and refunded once (unique idempotency keys) — replays are no-ops.
 */
class MembershipWalletService
{
    public function creditPurchase(Membership $membership): ?MembershipTransaction
    {
        return $this->apply(
            $membership,
            Type::MembershipPurchase,
            $membership->total_nights,
            "membership:{$membership->id}:purchase",
            "Membership purchased — {$membership->total_nights} hotel nights credited",
        );
    }

    /** Deduct nights for a booking. Throws if the balance is insufficient. Idempotent per booking. */
    public function deductForBooking(Membership $membership, Booking $booking, int $nights): ?MembershipTransaction
    {
        return $this->apply(
            $membership,
            Type::BookingDeduction,
            -$nights,
            "booking:{$booking->id}:deduct",
            "Booking {$booking->booking_number} — {$nights} night(s) used",
            $booking,
        );
    }

    /**
     * Return the nights a booking used. Never returns more than was deducted, and never twice:
     * the "booking:{id}:refund" idempotency key is unique in the ledger.
     */
    public function refundForBooking(Booking $booking, string $reason): ?MembershipTransaction
    {
        $deduction = MembershipTransaction::query()
            ->where('idempotency_key', "booking:{$booking->id}:deduct")
            ->first();

        if (! $deduction) {
            return null; // nothing was ever deducted
        }

        $membership = Membership::query()->findOrFail($deduction->membership_id);
        $nights = abs($deduction->nights);

        // A membership that has already expired cannot be topped up — the nights lapse with it.
        if (! $membership->isUsable() && ! $membership->rule('nights_extend_past_expiry', false)) {
            Log::channel('membership')->info('Nights not returned: membership no longer active', [
                'booking_id' => $booking->id, 'membership_id' => $membership->id, 'nights' => $nights,
            ]);

            return null;
        }

        return $this->apply(
            $membership,
            Type::BookingCancellationRefund,
            $nights,
            "booking:{$booking->id}:refund",
            "Booking {$booking->booking_number} — {$nights} night(s) returned ({$reason})",
            $booking,
        );
    }

    public function expireRemaining(Membership $membership): ?MembershipTransaction
    {
        return DB::transaction(function () use ($membership) {
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->id);
            if ($locked->nights_balance <= 0) {
                return null;
            }

            return $this->applyLocked($locked, Type::Expiration, -$locked->nights_balance, "membership:{$locked->id}:expire",
                "Membership expired — {$locked->nights_balance} unused night(s) lapsed");
        });
    }

    /** Admin/manual correction. $nights is signed. Requires a reason; written to the audit log by the caller. */
    public function adjust(Membership $membership, int $nights, string $reason, int $adminId, bool $manual = false): MembershipTransaction
    {
        if ($nights === 0) {
            throw DomainException::make('Adjustment must be a non-zero number of nights.', 'invalid_adjustment');
        }

        $type = $manual ? Type::ManualAdjustment : Type::AdminAdjustment;
        $key = "adjust:{$membership->id}:".bin2hex(random_bytes(8));

        return $this->apply($membership, $type, $nights, $key, $reason, null, $adminId);
    }

    /** Recompute the balance from the ledger — used by the integrity check command and tests. */
    public function ledgerBalance(Membership $membership): int
    {
        return (int) MembershipTransaction::query()->where('membership_id', $membership->id)->sum('nights');
    }

    private function apply(
        Membership $membership,
        Type $type,
        int $nights,
        string $idempotencyKey,
        string $description,
        ?Booking $booking = null,
        ?int $createdBy = null,
    ): ?MembershipTransaction {
        // Works whether or not the caller already opened a transaction (nested = savepoint).
        return DB::transaction(function () use ($membership, $type, $nights, $idempotencyKey, $description, $booking, $createdBy) {
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->id);

            return $this->applyLocked($locked, $type, $nights, $idempotencyKey, $description, $booking, $createdBy);
        });
    }

    private function applyLocked(
        Membership $locked,
        Type $type,
        int $nights,
        string $idempotencyKey,
        string $description,
        ?Booking $booking = null,
        ?int $createdBy = null,
    ): ?MembershipTransaction {
        // Idempotency: if this operation already happened, do nothing.
        if (MembershipTransaction::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            Log::channel('membership')->notice('Duplicate membership transaction ignored', ['key' => $idempotencyKey]);

            return null;
        }

        $before = $locked->nights_balance;
        $after = $before + $nights;

        if ($after < 0) {
            Log::channel('membership')->warning('Insufficient membership nights', [
                'membership_id' => $locked->id, 'balance' => $before, 'requested' => abs($nights),
            ]);
            throw DomainException::make(
                "You have {$before} membership night(s) remaining but this booking needs ".abs($nights).'.',
                'insufficient_membership_nights',
                422,
                ['available' => $before, 'required' => abs($nights)],
            );
        }
        $isAdjustment = in_array($type, [Type::AdminAdjustment, Type::ManualAdjustment], true);
        if ($after > $locked->total_nights && ! $isAdjustment) {
            Log::channel('membership')->error('Ledger credit would exceed total nights', ['membership_id' => $locked->id, 'key' => $idempotencyKey]);
            throw DomainException::make('This change would exceed the membership\'s total nights.', 'invalid_adjustment');
        }

        try {
            $tx = MembershipTransaction::query()->create([
                'user_id' => $locked->user_id,
                'membership_id' => $locked->id,
                'type' => $type,
                'nights' => $nights,
                'balance_before' => $before,
                'balance_after' => $after,
                'booking_id' => $booking?->id,
                'description' => mb_substr($description, 0, 255),
                'created_by' => $createdBy,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            // Unique key race: another process recorded it first.
            if (str_contains($e->getMessage(), 'idempotency_key')) {
                return null;
            }
            Log::channel('membership')->error('Membership transaction failed', ['key' => $idempotencyKey, 'error' => $e->getMessage()]);
            throw $e;
        }

        // Goodwill/bonus nights granted by an admin raise the membership's total allowance.
        $fill = ['nights_balance' => $after];
        if ($after > $locked->total_nights) {
            $fill['total_nights'] = $after;
        }
        $locked->forceFill($fill)->save();

        Log::channel('membership')->info('Membership ledger entry', [
            'membership_id' => $locked->id, 'type' => $type->value, 'nights' => $nights, 'before' => $before, 'after' => $after,
        ]);

        return $tx;
    }
}
