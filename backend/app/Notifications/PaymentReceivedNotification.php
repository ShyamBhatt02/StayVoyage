<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Money;

class PaymentReceivedNotification extends AppNotification
{
    public function __construct(public readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database']; // the booking/membership confirmation email already includes payment details
    }

    protected function title(): string
    {
        return 'Payment received';
    }

    protected function category(): string
    {
        return 'payment';
    }

    protected function lines(object $notifiable): array
    {
        return ['We received '.Money::format($this->payment->amount, $this->payment->currency)." (ref {$this->payment->reference}). Thank you!"];
    }

    protected function actionText(): ?string
    {
        return 'View payments';
    }

    protected function actionPath(): ?string
    {
        return '/account/payments';
    }
}
