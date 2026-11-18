<?php

namespace App\Notifications;

class WelcomeNotification extends AppNotification
{
    protected function title(): string
    {
        return 'Welcome to StayVoyage';
    }

    protected function category(): string
    {
        return 'account';
    }

    protected function lines(object $notifiable): array
    {
        return [
            'Your account is ready. Search thousands of nights across our hand-picked hotels, or build a trip day by day with the Tour Builder.',
            'Members get up to 30 hotel nights every month — explore the StayVoyage Monthly membership whenever you are ready.',
        ];
    }

    protected function actionText(): ?string
    {
        return 'Start exploring';
    }

    protected function actionPath(): ?string
    {
        return '/hotels';
    }
}
