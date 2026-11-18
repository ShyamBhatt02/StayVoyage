<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice->invoice_number }}</title>
<style>
    /* StayVoyage brand tokens (mirrors frontend tailwind theme) */
    @page { margin: 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; color: #2B323B; font-size: 11px; line-height: 1.45; }
    .brand { color: #0E6B7B; }
    .muted { color: #6B7785; }
    .header { border-bottom: 3px solid #0E6B7B; padding-bottom: 14px; margin-bottom: 18px; }
    .logo { font-size: 22px; font-weight: bold; color: #0E5563; letter-spacing: 0.3px; }
    .logo span { color: #F2603F; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
    .pill-paid { background: #DCFCE7; color: #166534; }
    .pill-refunded, .pill-partially_refunded { background: #FEF3C7; color: #92400E; }
    .pill-pending, .pill-failed { background: #FEE2E2; color: #991B1B; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { vertical-align: top; padding: 0; }
    .items th { background: #EEF8F9; color: #0E5563; text-align: left; padding: 8px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; }
    .items td { padding: 9px 8px; border-bottom: 1px solid #DDE2E8; vertical-align: top; }
    .right { text-align: right; }
    .totals td { padding: 5px 8px; }
    .grand td { border-top: 2px solid #0E6B7B; font-size: 13px; font-weight: bold; color: #0E5563; padding-top: 8px; }
    .footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #DDE2E8; font-size: 9.5px; color: #6B7785; }
    h3 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6B7785; }
</style>
</head>
<body>
<div class="header">
    <table class="meta">
        <tr>
            <td>
                <div class="logo">Stay<span>Voyage</span></div>
                <div class="muted">{{ $site['site.tagline'] }}</div>
                <div class="muted" style="margin-top:6px">{{ $site['site.address'] }}<br>{{ $site['site.support_email'] }} · {{ $site['site.support_phone'] }}</div>
            </td>
            <td class="right">
                <div style="font-size:20px;font-weight:bold;color:#0E5563">TAX INVOICE</div>
                <div><strong>{{ $invoice->invoice_number }}</strong></div>
                <div class="muted">Issued {{ $invoice->issued_at->format('j M Y') }}</div>
                <div style="margin-top:6px"><span class="pill pill-{{ $invoice->payment_status }}">{{ str_replace('_', ' ', $invoice->payment_status) }}</span></div>
            </td>
        </tr>
    </table>
</div>

<table class="meta" style="margin-bottom:18px">
    <tr>
        <td style="width:50%">
            <h3>Billed to</h3>
            <strong>{{ $invoice->billing_details['name'] ?? '' }}</strong><br>
            {{ $invoice->billing_details['email'] ?? '' }}<br>
            {{ $invoice->billing_details['phone'] ?? '' }}
        </td>
        <td style="width:50%" class="right">
            <h3>Reference</h3>
            <strong>{{ $invoice->billing_details['reference'] ?? '' }}</strong><br>
            @if($invoice->payment)
                Paid via {{ ucfirst($invoice->payment->gateway) }}{{ $invoice->payment->method ? ' · '.strtoupper($invoice->payment->method) : '' }}<br>
                <span class="muted">Txn {{ $invoice->payment->gateway_payment_id }}</span>
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead><tr><th style="width:70%">Description</th><th class="right">Amount</th></tr></thead>
    <tbody>
    @foreach($invoice->items as $item)
        <tr>
            <td><strong>{{ $item['description'] }}</strong>@if(!empty($item['details']))<br><span class="muted">{{ $item['details'] }}</span>@endif</td>
            <td class="right">{{ $item['amount'] < 0 ? '- ' : '' }}{{ $money(abs($item['amount'])) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="width:55%; margin-left:45%; margin-top:10px">
    <tr><td>Subtotal</td><td class="right">{{ $money($invoice->subtotal) }}</td></tr>
    @if($invoice->discount_amount > 0)
        <tr><td>Discount</td><td class="right">- {{ $money($invoice->discount_amount) }}</td></tr>
    @endif
    <tr><td>Taxes (GST)</td><td class="right">{{ $money($invoice->tax_amount) }}</td></tr>
    @if($invoice->service_fee > 0)
        <tr><td>Service fee</td><td class="right">{{ $money($invoice->service_fee) }}</td></tr>
    @endif
    <tr class="grand"><td>Total</td><td class="right">{{ $money($invoice->total) }}</td></tr>
    <tr><td class="muted">Amount paid</td><td class="right muted">{{ $money($invoice->amount_paid) }}</td></tr>
    @if($refunded > 0)
        <tr><td class="muted">Refunded</td><td class="right muted">- {{ $money($refunded) }}</td></tr>
    @endif
</table>

<div class="footer">
    This is a computer-generated invoice and does not require a signature. Prices are inclusive of applicable services as listed.
    For help with this invoice, contact {{ $site['site.support_email'] }} quoting {{ $invoice->invoice_number }}.
</div>
</body>
</html>
