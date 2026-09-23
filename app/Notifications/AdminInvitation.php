<?php

namespace App\Notifications;

use App\Models\Admin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent when a super admin invites someone to the back office.
 * Moves to the editable "Admin invite" email template in Sprint 3.
 */
class AdminInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $invitedBy) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Admin $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('admin.invitation.show', now()->addHours(72), ['admin' => $notifiable->id]);

        return (new MailMessage)
            ->subject('You’re invited to the RightAlly admin')
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->invitedBy} has invited you to the RightAlly onboarding admin as {$notifiable->role->name}.")
            ->action('Set your password', $url)
            ->line('This link expires in 72 hours. After setting a password you’ll set up two-factor authentication with an authenticator app.');
    }
}
