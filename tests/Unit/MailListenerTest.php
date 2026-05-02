<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mime\Email;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Listeners\MailListener;

function buildMessageSent(): MessageSent
{
    $email = (new Email)
        ->from('app@example.com')
        ->to('alice@example.com')
        ->cc('bob@example.com')
        ->subject('Welcome')
        ->text('Hello!');

    // Laravel's SentMessage wraps a Symfony SentMessage. Construct a minimal
    // mock that supports `getOriginalMessage()` for our listener.
    $sent = Mockery::mock(SentMessage::class);
    $sent->shouldReceive('getOriginalMessage')->andReturn($email);

    return new MessageSent($sent);
}

it('records a mail event with subject + recipients + attachments', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(MailListener::class);

    $listener->handle(buildMessageSent());

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['type'])->toBe('mail')
        ->and($event['subject'])->toBe('Welcome')
        ->and($event['to'])->toBe(['alice@example.com'])
        ->and($event['cc'])->toBe(['bob@example.com'])
        ->and($event['attachments_count'])->toBe(0)
        ->and($event['status'])->toBe('sent');
});

it('does nothing when no trace is active', function () {
    $listener = $this->app->make(MailListener::class);
    $listener->handle(buildMessageSent());

    expect(Uptimex::buffer())->toBeNull();
});
