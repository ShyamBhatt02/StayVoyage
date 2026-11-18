<?php

namespace App\Notifications;

use App\Models\Membership;

class MembershipExpiredNotification extends AppNotification
{
    public function __construct(public readonly Membership $membership) {}

    protected function title(): string
    {
        return 'Your membership has ended';
    }

    protected function category(): string
    {
        return 'membership';
    }

    protected function lines(object $notifiable): array
    {
        return [
            "Your {$this->membership->plan_snapshot['name']} membership ({$this->membership->membership_number}) ended on {$this->membership->expires_at->format('j M Y')}.",
            'Bookings you already made are not affected. Rejoin any time to get a fresh set of membership nights.',
        ];
    }

    protected function actionText(): ?string
    {
        return 'Rejoin StayVoyage Membership';
    }

    protected function actionPath(): ?string
    {
        return '/membership';
    }
}
