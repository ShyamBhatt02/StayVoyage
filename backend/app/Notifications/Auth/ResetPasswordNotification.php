<?php

namespace App\Notifications\Auth;

use App\Notifications\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = AppNotification::frontendUrl('reset-password?token='.$this->token.'&email='.urlencode($notifiable->getEmailForPasswordReset()));

        return (new MailMessage())
            ->subject('Reset your password | StayVoyage')
            ->greeting('Hi '.strtok((string) $notifiable->name, ' ').',')
            ->line('We received a request to reset the password for your StayVoyage account.')
            ->action('Choose a new password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire', 60).' minutes. If you did not request a reset, no action is needed — your password stays the same.')
            ->salutation("Happy travels,\nThe StayVoyage Team");
    }
}
