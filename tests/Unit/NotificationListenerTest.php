<?php

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Listeners\NotificationListener;

class FakeInvoiceNotification extends Notification {}

class FakeNotifiable
{
    public function getKey(): int
    {
        return 42;
    }

    public function routeNotificationFor(string $channel): ?string
    {
        return $channel === 'mail' ? 'alice@example.com' : null;
    }
}

it('records a notification.sent event', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(NotificationListener::class);

    $listener->onSent(new NotificationSent(new FakeNotifiable, new FakeInvoiceNotification, 'mail', null));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['type'])->toBe('notification')
        ->and($event['notification_class'])->toBe(FakeInvoiceNotification::class)
        ->and($event['channel'])->toBe('mail')
        ->and($event['recipient'])->toBe('alice@example.com')
        ->and($event['status'])->toBe('sent');
});

it('records a notification.failed event', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(NotificationListener::class);

    $listener->onFailed(new NotificationFailed(new FakeNotifiable, new FakeInvoiceNotification, 'mail', []));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events[0]['status'])->toBe('failed');
});
