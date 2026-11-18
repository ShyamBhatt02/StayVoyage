<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Membership;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Creates (or refreshes) the invoice record and pre-renders its PDF in the background. */
class GenerateInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $type, public readonly int $id)
    {
        $this->afterCommit();
    }

    public function handle(InvoiceService $invoices): void
    {
        $invoice = match ($this->type) {
            'booking' => ($b = Booking::withTrashed()->find($this->id)) ? $invoices->forBooking($b) : null,
            'membership' => ($m = Membership::withTrashed()->find($this->id)) ? $invoices->forMembership($m) : null,
            default => null,
        };
        if ($invoice) {
            $invoice->forceFill(['pdf_path' => null])->saveQuietly(); // re-render with latest status
            $invoices->pdf($invoice);
        }
    }
}
