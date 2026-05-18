<?php

use Illuminate\Support\Facades\Log;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Logging\UptimexLogChannel;
use Uptimex\Client\UptimexServiceProvider;

/*
 * The SDK auto-registers a `uptimex` log channel into the host's logging
 * config so capturing logs needs no `config/logging.php` edit — the operator
 * just adds `uptimex` to their LOG_STACK. A channel the host defined itself
 * is never overwritten.
 */

it('auto-registers the uptimex log channel without a config/logging.php edit', function () {
    $channel = config('logging.channels.uptimex');

    expect($channel)->toBeArray()
        ->and($channel['driver'])->toBe('custom')
        ->and($channel['via'])->toBe(UptimexLogChannel::class)
        ->and($channel['level'])->toBe(env('UPTIMEX_LOG_LEVEL', 'debug'));
});

it('does not clobber a uptimex log channel the host defined', function () {
    $hostChannel = ['driver' => 'single', 'path' => '/tmp/host-uptimex.log'];
    config()->set('logging.channels.uptimex', $hostChannel);

    // Re-run registration: the guard must see the existing channel and skip.
    (new UptimexServiceProvider($this->app))->register();

    expect(config('logging.channels.uptimex'))->toBe($hostChannel);
});

it('captures a log written through the uptimex channel as a log event', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    Log::channel('uptimex')->warning('disk is filling up', ['free_pct' => 4]);

    $events = Uptimex::buffer()?->all() ?? [];

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('log')
        ->and($events[0]['level'])->toBe('warning')
        ->and($events[0]['channel'])->toBe('uptimex')
        ->and($events[0]['message'])->toBe('disk is filling up')
        ->and($events[0]['context'])->toBe(['free_pct' => 4]);
});

it('drops log records below the channel level UPTIMEX_LOG_LEVEL sets', function () {
    // UPTIMEX_LOG_LEVEL feeds the channel's `level` key; raise it to `error`.
    config()->set('logging.channels.uptimex', [
        'driver' => 'custom',
        'via' => UptimexLogChannel::class,
        'level' => 'error',
    ]);
    Log::forgetChannel('uptimex');

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    Log::channel('uptimex')->info('routine noise');
    Log::channel('uptimex')->error('the bad thing');

    $events = Uptimex::buffer()?->all() ?? [];

    expect($events)->toHaveCount(1)
        ->and($events[0]['level'])->toBe('error')
        ->and($events[0]['message'])->toBe('the bad thing');
});
