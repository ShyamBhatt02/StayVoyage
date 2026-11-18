<?php

namespace App\Services;

use App\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reporting queries (revenue, bookings, memberships, hotels, tours, customers, refunds) over a date range.
 * Revenue is recognised on payment date (paid_at); refunds on processed_at.
 */
class ReportService
{
    public const TYPES = ['revenue', 'bookings', 'memberships', 'hotels', 'tours', 'customers', 'refunds'];

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:string} */
    public function range(?string $preset, ?string $from, ?string $to): array
    {
        $today = CarbonImmutable::today();
        [$start, $end, $label] = match ($preset ?? 'last_30_days') {
            'today' => [$today, $today, 'Today'],
            'yesterday' => [$today->subDay(), $today->subDay(), 'Yesterday'],
            'last_7_days' => [$today->subDays(6), $today, 'Last 7 days'],
            'this_month' => [$today->startOfMonth(), $today, 'This month'],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth(), 'Last month'],
            'custom' => [
                CarbonImmutable::parse($from ?? throw DomainException::make('Choose a start date.', 'invalid_range')),
                CarbonImmutable::parse($to ?? throw DomainException::make('Choose an end date.', 'invalid_range')),
                'Custom range',
            ],
            default => [$today->subDays(29), $today, 'Last 30 days'],
        };
        if ($end->lt($start)) {
            throw DomainException::make('The end date must be after the start date.', 'invalid_range');
        }
        if ($start->diffInDays($end) > 731) {
            throw DomainException::make('Reports are limited to two years at a time.', 'invalid_range');
        }

        return [$start->startOfDay(), $end->endOfDay(), $label];
    }

    /** @return array{columns: array<int,string>, rows: array<int,array>, summary: array} */
    public function build(string $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return match ($type) {
            'revenue' => $this->revenue($from, $to),
            'bookings' => $this->bookings($from, $to),
            'memberships' => $this->memberships($from, $to),
            'hotels' => $this->hotels($from, $to),
            'tours' => $this->tours($from, $to),
            'customers' => $this->customers($from, $to),
            'refunds' => $this->refunds($from, $to),
            default => throw DomainException::make('Unknown report.', 'invalid_report', 404),
        };
    }

    private function revenue($from, $to): array
    {
        $rows = DB::table('payments')
            ->where('status', '!=', 'pending')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->selectRaw("DATE(paid_at) as day,
                SUM(CASE WHEN payable_type='membership' THEN amount ELSE 0 END) as membership,
                SUM(CASE WHEN payable_type='booking' AND EXISTS(SELECT 1 FROM bookings b WHERE b.id=payments.payable_id AND b.type='hotel') THEN amount ELSE 0 END) as hotel,
                SUM(CASE WHEN payable_type='booking' AND EXISTS(SELECT 1 FROM bookings b WHERE b.id=payments.payable_id AND b.type='tour') THEN amount ELSE 0 END) as tour,
                SUM(amount) as gross, SUM(amount_refunded) as refunded, COUNT(*) as payments")
            ->groupByRaw('DATE(paid_at)')->orderBy('day')->get()
            ->map(fn ($r) => [$r->day, (float) $r->hotel, (float) $r->tour, (float) $r->membership, (float) $r->gross, (float) $r->refunded, round($r->gross - $r->refunded, 2), (int) $r->payments])
            ->all();

        return [
            'columns' => ['Date', 'Hotel revenue', 'Tour revenue', 'Membership revenue', 'Gross', 'Refunded', 'Net', 'Payments'],
            'rows' => $rows,
            'summary' => [
                'gross' => round(array_sum(array_column($rows, 4)), 2),
                'refunded' => round(array_sum(array_column($rows, 5)), 2),
                'net' => round(array_sum(array_column($rows, 6)), 2),
                'payments' => array_sum(array_column($rows, 7)),
            ],
        ];
    }

    private function bookings($from, $to): array
    {
        $rows = DB::table('bookings')->leftJoin('hotels', 'hotels.id', '=', 'bookings.hotel_id')->leftJoin('users', 'users.id', '=', 'bookings.user_id')
            ->whereBetween('bookings.created_at', [$from, $to])->whereNull('bookings.deleted_at')
            ->orderByDesc('bookings.created_at')
            ->get(['bookings.booking_number', 'bookings.created_at', 'bookings.type', 'bookings.booking_type', 'users.name as customer', 'hotels.name as hotel',
                'bookings.check_in', 'bookings.check_out', 'bookings.status', 'bookings.payment_status', 'bookings.membership_nights_used', 'bookings.total_amount', 'bookings.amount_paid'])
            ->map(fn ($b) => [$b->booking_number, substr($b->created_at, 0, 16), ucfirst($b->type), ucfirst($b->booking_type), $b->customer, $b->hotel ?? '—',
                $b->check_in, $b->check_out, $b->status, $b->payment_status, (int) $b->membership_nights_used, (float) $b->total_amount, (float) $b->amount_paid])
            ->all();

        $byStatus = collect($rows)->countBy(8)->all();

        return [
            'columns' => ['Booking', 'Created', 'Type', 'Booking type', 'Customer', 'Hotel', 'Check-in', 'Check-out', 'Status', 'Payment', 'Member nights', 'Total', 'Paid'],
            'rows' => $rows,
            'summary' => ['total' => count($rows), 'by_status' => $byStatus, 'value' => round(array_sum(array_column($rows, 11)), 2)],
        ];
    }

    private function memberships($from, $to): array
    {
        $rows = DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->whereBetween('memberships.activated_at', [$from, $to])
            ->orderByDesc('memberships.activated_at')
            ->get(['memberships.membership_number', 'users.name', 'users.email', 'memberships.plan_snapshot', 'memberships.activated_at',
                'memberships.expires_at', 'memberships.status', 'memberships.total_nights', 'memberships.nights_balance', 'memberships.price_paid'])
            ->map(fn ($m) => [$m->membership_number, $m->name, $m->email, json_decode($m->plan_snapshot, true)['name'] ?? '', substr((string) $m->activated_at, 0, 10),
                substr((string) $m->expires_at, 0, 10), $m->status, (int) $m->total_nights, (int) $m->total_nights - (int) $m->nights_balance, (float) $m->price_paid])
            ->all();

        return [
            'columns' => ['Membership', 'Customer', 'Email', 'Plan', 'Activated', 'Expires', 'Status', 'Nights', 'Used', 'Paid'],
            'rows' => $rows,
            'summary' => [
                'sold' => count($rows),
                'revenue' => round(array_sum(array_column($rows, 9)), 2),
                'nights_used' => array_sum(array_column($rows, 8)),
                'utilisation_percent' => array_sum(array_column($rows, 7)) ? round(array_sum(array_column($rows, 8)) / array_sum(array_column($rows, 7)) * 100, 1) : 0,
            ],
        ];
    }

    private function hotels($from, $to): array
    {
        $rows = DB::table('hotels')
            ->leftJoin('bookings', fn ($j) => $j->on('bookings.hotel_id', '=', 'hotels.id')->whereIn('bookings.status', ['confirmed', 'completed'])->whereBetween('bookings.check_in', [$from->toDateString(), $to->toDateString()]))
            ->whereNull('hotels.deleted_at')
            ->groupBy('hotels.id', 'hotels.name', 'hotels.city', 'hotels.rating_avg')
            ->selectRaw('hotels.name, hotels.city, hotels.rating_avg, COUNT(bookings.id) as bookings, COALESCE(SUM(bookings.nights * bookings.rooms_count),0) as room_nights,
                COALESCE(SUM(CASE WHEN bookings.booking_type="membership" THEN bookings.membership_nights_used ELSE 0 END),0) as member_nights,
                COALESCE(SUM(bookings.amount_paid - bookings.amount_refunded),0) as revenue, COALESCE(SUM(bookings.room_amount),0) as gross_stay_value')
            ->orderByDesc('revenue')->get()
            ->map(fn ($h) => [$h->name, $h->city, (float) $h->rating_avg, (int) $h->bookings, (int) $h->room_nights, (int) $h->member_nights, (float) $h->gross_stay_value, (float) $h->revenue])
            ->all();

        return [
            'columns' => ['Hotel', 'City', 'Rating', 'Bookings', 'Room nights', 'Member nights', 'Stay value', 'Net revenue'],
            'rows' => $rows,
            'summary' => ['hotels' => count($rows), 'room_nights' => array_sum(array_column($rows, 4)), 'revenue' => round(array_sum(array_column($rows, 7)), 2)],
        ];
    }

    private function tours($from, $to): array
    {
        $rows = DB::table('bookings')->join('users', 'users.id', '=', 'bookings.user_id')
            ->where('bookings.type', 'tour')->whereBetween('bookings.created_at', [$from, $to])->whereNull('bookings.deleted_at')
            ->orderByDesc('bookings.created_at')
            ->get(['bookings.booking_number', 'bookings.itinerary_snapshot', 'users.name', 'bookings.check_in', 'bookings.check_out', 'bookings.adults', 'bookings.children',
                'bookings.status', 'bookings.total_amount', 'bookings.amount_paid'])
            ->map(fn ($b) => [$b->booking_number, json_decode((string) $b->itinerary_snapshot, true)['title'] ?? 'Custom trip', $b->name, $b->check_in, $b->check_out,
                (int) $b->adults + (int) $b->children, $b->status, (float) $b->total_amount, (float) $b->amount_paid])
            ->all();

        return [
            'columns' => ['Booking', 'Trip', 'Customer', 'Start', 'End', 'Travellers', 'Status', 'Total', 'Paid'],
            'rows' => $rows,
            'summary' => ['bookings' => count($rows), 'travellers' => array_sum(array_column($rows, 5)), 'revenue' => round(array_sum(array_column($rows, 8)), 2)],
        ];
    }

    private function customers($from, $to): array
    {
        $rows = DB::table('users')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('roles.slug', 'customer')
            ->whereBetween('users.created_at', [$from, $to])->whereNull('users.deleted_at')
            ->leftJoin('bookings', fn ($j) => $j->on('bookings.user_id', '=', 'users.id')->whereIn('bookings.status', ['confirmed', 'completed']))
            ->groupBy('users.id', 'users.name', 'users.email', 'users.phone', 'users.created_at', 'users.status')
            ->selectRaw('users.name, users.email, users.phone, users.created_at, users.status, COUNT(bookings.id) as bookings, COALESCE(SUM(bookings.amount_paid - bookings.amount_refunded),0) as spend,
                (SELECT COUNT(*) FROM memberships m WHERE m.user_id = users.id AND m.status IN ("active","expired")) as memberships')
            ->orderByDesc('users.created_at')->get()
            ->map(fn ($u) => [$u->name, $u->email, $u->phone, substr($u->created_at, 0, 10), $u->status, (int) $u->bookings, (int) $u->memberships, (float) $u->spend])
            ->all();

        return [
            'columns' => ['Name', 'Email', 'Phone', 'Joined', 'Status', 'Bookings', 'Memberships', 'Lifetime spend'],
            'rows' => $rows,
            'summary' => ['new_customers' => count($rows), 'with_bookings' => count(array_filter($rows, fn ($r) => $r[5] > 0)), 'spend' => round(array_sum(array_column($rows, 7)), 2)],
        ];
    }

    private function refunds($from, $to): array
    {
        $rows = DB::table('refunds')->join('payments', 'payments.id', '=', 'refunds.payment_id')->join('users', 'users.id', '=', 'refunds.user_id')
            ->leftJoin('bookings', 'bookings.id', '=', 'refunds.booking_id')
            ->whereBetween('refunds.created_at', [$from, $to])->orderByDesc('refunds.created_at')
            ->get(['refunds.reference', 'refunds.created_at', 'users.name', 'bookings.booking_number', 'payments.gateway', 'refunds.amount', 'refunds.status', 'refunds.reason'])
            ->map(fn ($r) => [$r->reference, substr($r->created_at, 0, 16), $r->name, $r->booking_number ?? '—', $r->gateway, (float) $r->amount, $r->status, $r->reason])
            ->all();

        return [
            'columns' => ['Refund', 'Date', 'Customer', 'Booking', 'Gateway', 'Amount', 'Status', 'Reason'],
            'rows' => $rows,
            'summary' => ['refunds' => count($rows), 'amount' => round(array_sum(array_column(array_filter($rows, fn ($r) => $r[6] === 'processed'), 5)), 2),
                'failed' => count(array_filter($rows, fn ($r) => $r[6] === 'failed'))],
        ];
    }
}
