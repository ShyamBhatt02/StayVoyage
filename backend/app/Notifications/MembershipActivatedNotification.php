<?php

namespace App\Notifications;

use App\Models\Membership;

class MembershipActivatedNotification extends AppNotification
{
    public function __construct(public readonly Membership $membership) {}

    protected function title(): string
    {
        return $this->membership->renewed_from_id ? 'Your membership renewal is confirmed' : 'Your membership is active';
    }

    protected function category(): string
    {
        return 'membership';
    }

    protected function lines(object $notifiable): array
    {
        $m = $this->membership;
        $starts = $m->starts_at?->isFuture() ? 'starts on '.$m->starts_at->format('j M Y').' and ' : '';

        return [
            "{$m->plan_snapshot['name']} ({$m->membership_number}) {$starts}runs until {$m->expires_at?->format('j M Y')}.",
            "You have {$m->total_nights} membership hotel nights to use at eligible StayVoyage hotels. Look for the “Membership Eligible” badge when you search.",
            'Payment of ₹'.number_format($m->price_paid, 2).' was received — your invoice is available in your account.',
        ];
    }

    protected function actionText(): ?string
    {
        return 'Find a membership stay';
    }

    protected function actionPath(): ?string
    {
        return '/hotels?membership=1';
    }
}
