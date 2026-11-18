<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for all customer notifications: queued, delivered by email + stored for the in-app notification centre.
 * Subclasses describe the content once via title()/body()/actionUrl() and get both channels for free.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable; // queued only after the surrounding DB transaction commits (queue.connections.redis.after_commit)

    public int $tries = 3;

    abstract protected function title(): string;

    /** @return array<int,string> paragraphs */
    abstract protected function lines(object $notifiable): array;

    abstract protected function category(): string; // booking | membership | payment | trip | account

    protected function actionText(): ?string
    {
        return null;
    }

    /** SPA-relative path, e.g. /account/bookings/SVH-2611-ABC123 */
    protected function actionPath(): ?string
    {
        return null;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject($this->title().' | StayVoyage')
            ->greeting('Hi '.strtok((string) $notifiable->name, ' ').',');
        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }
        if ($this->actionText() && $this->actionPath()) {
            $mail->action($this->actionText(), self::frontendUrl($this->actionPath()));
        }

        return $mail->salutation("Happy travels,\nThe StayVoyage Team");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => implode(' ', array_slice($this->lines($notifiable), 0, 2)),
            'category' => $this->category(),
            'action_text' => $this->actionText(),
            'action_path' => $this->actionPath(),
        ];
    }

    public static function frontendUrl(string $path): string
    {
        return rtrim((string) config('payments.frontend_url', config('app.url')), '/').'/'.ltrim($path, '/');
    }
}
