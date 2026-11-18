<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Money;

class PaymentFailedNotification extends AppNotification
{
    public function __construct(public readonly Payment $payment) {}

    protected function title(): string
    {
        return 'Payment was not completed';
    }

    protected function category(): string
    {
        return 'payment';
    }

    protected function lines(object $notifiable): array
    {
        return [
            'Your payment of '.Money::format($this->payment->amount, $this->payment->currency).' could not be completed'.($this->payment->failure_reason ? ': '.$this->payment->failure_reason : '.'),
            'No money was taken. Your reservation is held for a short time — you can retry the payment from your bookings page.',
        ];
    }

    protected function actionText(): ?string
    {
        return 'Retry payment';
    }

    protected function actionPath(): ?string
    {
        return $this->payment->payable_type === 'booking' ? '/account/bookings' : '/membership';
    }
}
