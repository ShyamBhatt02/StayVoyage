<?php

namespace App\Notifications;

use App\Models\Membership;

class MembershipExpiringNotification extends AppNotification
{
    public function __construct(public readonly Membership $membership) {}

    protected function title(): string
    {
        return 'Your membership expires soon';
    }

    protected function category(): string
    {
        return 'membership';
    }

    protected function lines(object $notifiable): array
    {
        $m = $this->membership;
        $lines = ["Your {$m->plan_snapshot['name']} membership expires on {$m->expires_at->format('j M Y')}. You still have {$m->nights_balance} night(s) to use."];
        $lines[] = $m->auto_renew
            ? 'Auto-renewal is on: complete the renewal payment from your membership page and your new nights start the moment this period ends.'
            : 'Renew now to keep your benefits — your new membership will start right after the current one ends, so you lose nothing.';

        return $lines;
    }

    protected function actionText(): ?string
    {
        return 'Renew membership';
    }

    protected function actionPath(): ?string
    {
        return '/membership?renew=1';
    }
}
