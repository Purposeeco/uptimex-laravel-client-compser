<?php

use Monolog\Level;
use Monolog\Logger;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Logging\UptimexLogHandler;

it('records a log event under the active trace', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    $logger = new Logger('app');
    $logger->pushHandler(new UptimexLogHandler($this->app->make(\Uptimex\Client\Uptimex::class)));

    $logger->warning('something happened', ['user_id' => 7]);

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['type'])->toBe('log')
        ->and($event['level'])->toBe('warning')
        ->and($event['channel'])->toBe('app')
        ->and($event['message'])->toBe('something happened')
        ->and($event['context'])->toBe(['user_id' => 7]);
});

it('respects the configured min level', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    $logger = new Logger('app');
    $logger->pushHandler(new UptimexLogHandler($this->app->make(\Uptimex\Client\Uptimex::class), Level::Warning));

    $logger->info('quiet');
    $logger->warning('noisy');

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1)
        ->and($events[0]['message'])->toBe('noisy');
});

it('does nothing when no trace is active', function () {
    $logger = new Logger('app');
    $logger->pushHandler(new UptimexLogHandler($this->app->make(\Uptimex\Client\Uptimex::class)));

    $logger->error('lost');

    expect(Uptimex::buffer())->toBeNull();
});

it('serializes throwables in context', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    $logger = new Logger('app');
    $logger->pushHandler(new UptimexLogHandler($this->app->make(\Uptimex\Client\Uptimex::class)));

    $logger->error('boom', ['exception' => new RuntimeException('bad things')]);

    $event = Uptimex::buffer()?->all()[0] ?? null;
    expect($event['context']['exception'])->toBe(['__exception' => 'RuntimeException', 'message' => 'bad things']);
});
