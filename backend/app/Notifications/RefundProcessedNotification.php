<?php

namespace App\Notifications;

use App\Models\Refund;
use App\Support\Money;

class RefundProcessedNotification extends AppNotification
{
    public function __construct(public readonly Refund $refund) {}

    protected function title(): string
    {
        return 'Refund processed';
    }

    protected function category(): string
    {
        return 'payment';
    }

    protected function lines(object $notifiable): array
    {
        return [
            'We have refunded '.Money::format($this->refund->amount, $this->refund->currency)." (ref {$this->refund->reference}) to your original payment method.",
            'Banks typically take 5–7 working days to show the credit on your statement.',
        ];
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
