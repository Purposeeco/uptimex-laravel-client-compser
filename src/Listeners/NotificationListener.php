<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Uptimex\Client\Uptimex;

final class NotificationListener
{
    public function __construct(private readonly Uptimex $uptimex) {}

    public function onSent(NotificationSent $event): void
    {
        $this->record($event->notification::class, $event->channel, $event->notifiable, 'sent');
    }

    public function onFailed(NotificationFailed $event): void
    {
        $this->record($event->notification::class, $event->channel, $event->notifiable, 'failed');
    }

    private function record(string $class, string $channel, mixed $notifiable, string $status): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $this->uptimex->record('notification', [
            'notification_class' => $class,
            'channel' => mb_substr($channel, 0, 64),
            'recipient' => $this->resolveRecipient($notifiable, $channel),
            'status' => $status,
        ]);
    }

    /**
     * Best-effort recipient identifier. For mail it's the email; for SMS it's
     * the phone number; otherwise the notifiable's primary key + class name.
     */
    private function resolveRecipient(mixed $notifiable, string $channel): ?string
    {
        if ($channel === 'mail' && method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('mail');
            if (is_string($route)) {
                return mb_substr($route, 0, 255);
            }
            if (is_array($route) && $route !== []) {
                return mb_substr((string) array_key_first($route), 0, 255);
            }
        }

        if (is_object($notifiable) && method_exists($notifiable, 'getKey')) {
            return mb_substr($notifiable::class.'#'.$notifiable->getKey(), 0, 255);
        }

        return null;
    }
}
