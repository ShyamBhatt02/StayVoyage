<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Membership;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function forBooking(Booking $booking): Invoice
    {
        $booking->loadMissing(['user', 'hotel', 'rooms.room', 'payments', 'trip']);
        $existing = $booking->invoice()->first();
        if ($existing) {
            return $this->syncStatus($existing, $booking);
        }

        $items = [];
        if ($booking->isHotel()) {
            foreach ($booking->rooms as $line) {
                $items[] = [
                    'description' => ($booking->hotel?->name ?? 'Hotel').' — '.($line->room?->name ?? 'Room'),
                    'details' => $line->check_in->format('j M Y').' → '.$line->check_out->format('j M Y').' · '.$line->nights.' night(s) × '.$line->quantity.' room(s)',
                    'amount' => $line->amount,
                ];
            }
            if ($booking->membership_covered_amount > 0) {
                $items[] = [
                    'description' => 'Covered by StayVoyage membership',
                    'details' => $booking->membership_nights_used.' membership night credit(s)',
                    'amount' => -$booking->membership_covered_amount,
                ];
            }
        } else {
            $groups = $booking->price_breakdown['groups'] ?? [];
            $labels = ['transportation' => 'Transportation', 'accommodation' => 'Accommodation', 'activities' => 'Activities & attractions',
                'guides' => 'Tour guides', 'meals' => 'Meals & dining', 'transfers' => 'Transfers', 'other' => 'Other services'];
            foreach ($groups as $key => $amount) {
                if ($amount > 0) {
                    $items[] = ['description' => $labels[$key] ?? ucfirst($key), 'details' => $booking->itinerary_snapshot['title'] ?? 'Custom trip', 'amount' => $amount];
                }
            }
        }

        return $this->create($booking, $booking->user_id, $items, [
            'subtotal' => Money::round(array_sum(array_column($items, 'amount'))),
            'discount' => $booking->discount_amount,
            'tax' => $booking->tax_amount,
            'fee' => $booking->service_fee,
            'total' => $booking->total_amount,
            'paid' => $booking->amount_paid,
            'status' => $booking->payment_status->value,
            'currency' => $booking->currency,
            'payment_id' => $booking->payments->firstWhere('status', PaymentStatus::Paid)?->id,
            'billing' => ['name' => $booking->contact_name, 'email' => $booking->contact_email, 'phone' => $booking->contact_phone, 'reference' => $booking->booking_number],
        ]);
    }

    public function forMembership(Membership $membership): Invoice
    {
        $membership->loadMissing(['user', 'payments']);
        $existing = $membership->invoice()->first();
        if ($existing) {
            return $existing;
        }
        $quote = $membership->plan_snapshot['quote'] ?? [];
        $items = [[
            'description' => ($membership->plan_snapshot['name'] ?? 'StayVoyage Membership'),
            'details' => $membership->total_nights.' hotel nights · '.($membership->plan_snapshot['duration_days'] ?? 30).' days · '
                .$membership->starts_at?->format('j M Y').' → '.$membership->expires_at?->format('j M Y'),
            'amount' => $quote['price'] ?? $membership->price_paid,
        ]];
        $paid = $membership->payments->firstWhere('status', PaymentStatus::Paid);

        return $this->create($membership, $membership->user_id, $items, [
            'subtotal' => $quote['price'] ?? $membership->price_paid,
            'discount' => $quote['discount_amount'] ?? 0,
            'tax' => $quote['tax_amount'] ?? 0,
            'fee' => 0,
            'total' => $membership->price_paid,
            'paid' => $paid?->amount ?? $membership->price_paid,
            'status' => 'paid',
            'currency' => $membership->currency,
            'payment_id' => $paid?->id,
            'billing' => ['name' => $membership->user->name, 'email' => $membership->user->email, 'phone' => $membership->user->phone, 'reference' => $membership->membership_number],
        ]);
    }

    private function create($invoiceable, int $userId, array $items, array $t): Invoice
    {
        return DB::transaction(function () use ($invoiceable, $userId, $items, $t) {
            $invoice = Invoice::query()->create([
                'invoice_number' => 'TMP-'.bin2hex(random_bytes(8)),
                'user_id' => $userId,
                'invoiceable_type' => $invoiceable->getMorphClass(),
                'invoiceable_id' => $invoiceable->getKey(),
                'payment_id' => $t['payment_id'],
                'issued_at' => now(),
                'billing_details' => $t['billing'],
                'items' => $items,
                'currency' => $t['currency'],
                'subtotal' => $t['subtotal'],
                'discount_amount' => $t['discount'],
                'tax_amount' => $t['tax'],
                'service_fee' => $t['fee'],
                'total' => $t['total'],
                'amount_paid' => $t['paid'],
                'payment_status' => $t['status'],
            ]);
            $invoice->update(['invoice_number' => 'SVI-'.now()->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);

            return $invoice;
        });
    }

    public function syncStatus(Invoice $invoice, Booking $booking): Invoice
    {
        if ($invoice->payment_status !== $booking->payment_status->value) {
            $invoice->update(['payment_status' => $booking->payment_status->value, 'pdf_path' => null]);
        }

        return $invoice;
    }

    /** Render (and cache on the private local disk) the invoice PDF. */
    public function pdf(Invoice $invoice): string
    {
        $disk = Storage::disk('local');
        if ($invoice->pdf_path && $disk->exists($invoice->pdf_path)) {
            return $disk->get($invoice->pdf_path);
        }
        $invoice->loadMissing('invoiceable', 'payment');
        $refunded = $invoice->invoiceable instanceof Booking ? $invoice->invoiceable->amount_refunded : 0;

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'site' => $this->settings->all(),
            'refunded' => $refunded,
            'money' => fn ($v) => 'Rs. '.Money::indian((float) $v), // DejaVu has ₹ but "Rs." prints reliably everywhere
        ])->setPaper('a4');

        $output = $pdf->output();
        $path = 'invoices/'.$invoice->invoice_number.'.pdf';
        $disk->put($path, $output);
        $invoice->forceFill(['pdf_path' => $path])->saveQuietly();

        return $output;
    }
}
