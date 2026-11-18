<?php

namespace App\Notifications\Auth;

use App\Notifications\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Signed verification link pointing at the SPA, which forwards the signature to the API. */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Relative signature: valid regardless of host (Apache :8096 or the Vite dev proxy).
        $signed = URL::temporarySignedRoute('verification.verify', now()->addHours(24), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ], false);
        // Re-point the signed API URL at the SPA route, keeping id/hash/expires/signature intact.
        $query = parse_url($signed, PHP_URL_QUERY);
        $path = parse_url($signed, PHP_URL_PATH);
        $parts = explode('/', trim($path, '/'));
        $hash = array_pop($parts);
        $id = array_pop($parts);
        $url = AppNotification::frontendUrl("verify-email?id={$id}&hash={$hash}&{$query}");

        return (new MailMessage())
            ->subject('Verify your email | StayVoyage')
            ->greeting('Welcome aboard, '.strtok((string) $notifiable->name, ' ').'!')
            ->line('Please confirm your email address so we can send booking confirmations and membership updates.')
            ->action('Verify email address', $url)
            ->line('This link expires in 24 hours. If you did not create a StayVoyage account, you can ignore this email.')
            ->salutation("Happy travels,\nThe StayVoyage Team");
    }
}
