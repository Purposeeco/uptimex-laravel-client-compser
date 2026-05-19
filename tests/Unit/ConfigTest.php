<?php

/*
 * The ingest URL is not a customer knob. The cloud host is identical for
 * every tenant — the bearer token, not the URL, routes data to a workspace
 * — and a customer-supplied URL is a foot-gun (an `http://` scheme ships
 * telemetry in plaintext and trips the server's HTTPS redirect). So it is
 * hardcoded in the package config with no `env()` override. These tests
 * fail loudly if `env('UPTIMEX_INGEST_URL', ...)` is ever reintroduced.
 */

it('ships a hardcoded https ingest URL', function () {
    $config = require __DIR__.'/../../config/uptimex.php';

    expect($config['ingest_url'])->toBe('https://ingest.uptimex.tech');
});

it('exposes no UPTIMEX_INGEST_URL env override in the config file', function () {
    $source = file_get_contents(__DIR__.'/../../config/uptimex.php');

    expect($source)->not->toContain('UPTIMEX_INGEST_URL');
});

it('keeps internal performance-tuning keys out of env reach', function () {
    // Timeouts, buffers, queue/ship sizes and the retry bounds are managed,
    // optimized values — fixed constants, never env-tunable, so a customer
    // cannot mis-set them and slow their app or storm UptimeX's ingest.
    $source = file_get_contents(__DIR__.'/../../config/uptimex.php');

    expect($source)
        ->not->toContain('UPTIMEX_EVENT_BUFFER')
        ->not->toContain('UPTIMEX_FLUSH_TIMEOUT')
        ->not->toContain('UPTIMEX_CONNECT_TIMEOUT')
        ->not->toContain('UPTIMEX_AGENT_CONNECT_TIMEOUT_MS')
        ->not->toContain('UPTIMEX_AGENT_MAX_QUEUE')
        ->not->toContain('UPTIMEX_AGENT_SHIP_BATCH')
        ->not->toContain('UPTIMEX_AGENT_HEALTH_RECHECK')
        ->not->toContain('UPTIMEX_RETRY_BASE')
        ->not->toContain('UPTIMEX_RETRY_MAX')
        ->not->toContain('UPTIMEX_CONTEXT_MAX_BYTES');
});

it('bakes the performance-tuning keys at their managed values', function () {
    $config = require __DIR__.'/../../config/uptimex.php';

    expect($config['event_buffer'])->toBe(500)
        ->and($config['flush_timeout'])->toBe(0.5)
        ->and($config['connect_timeout'])->toBe(0.5)
        ->and($config['agent_connect_timeout_ms'])->toBe(50)
        ->and($config['agent_max_queue'])->toBe(10000)
        ->and($config['agent_ship_batch_size'])->toBe(20)
        ->and($config['agent_health_recheck_seconds'])->toBe(30)
        ->and($config['context_max_bytes'])->toBe(66560)
        ->and($config['retry_base_seconds'])->toBe(5)
        ->and($config['retry_max_seconds'])->toBe(300);
});

it('exposes no retired delivery env overrides in the config file', function () {
    // Agent-only delivery: there is one path, so neither a delivery mode nor
    // an agent fallback is a customer knob anymore.
    $source = file_get_contents(__DIR__.'/../../config/uptimex.php');

    expect($source)
        ->not->toContain('UPTIMEX_DELIVERY')
        ->not->toContain('UPTIMEX_AGENT_FALLBACK');
});

it('defines the agent delivery keys with their documented defaults', function () {
    $config = require __DIR__.'/../../config/uptimex.php';

    expect($config)->toHaveKeys([
        'agent_address', 'agent_connect_timeout_ms', 'agent_health_recheck_seconds',
        'agent_max_queue', 'agent_ship_batch_size',
        'retry_base_seconds', 'retry_max_seconds',
    ])
        ->and($config['agent_address'])->toBe('127.0.0.1:9237')
        ->and($config['agent_connect_timeout_ms'])->toBe(50)
        ->and($config['agent_health_recheck_seconds'])->toBe(30)
        ->and($config['agent_max_queue'])->toBe(10000)
        ->and($config['agent_ship_batch_size'])->toBe(20)
        ->and($config['retry_base_seconds'])->toBe(5)
        ->and($config['retry_max_seconds'])->toBe(300);
});

it('drops the retired spool, drain, and delivery-mode config keys', function () {
    $config = require __DIR__.'/../../config/uptimex.php';

    expect($config)->not->toHaveKey('spool_path')
        ->and($config)->not->toHaveKey('spool_max_files')
        ->and($config)->not->toHaveKey('spool_max_bytes')
        ->and($config)->not->toHaveKey('drain_auto')
        ->and($config)->not->toHaveKey('drain_max_batches')
        ->and($config)->not->toHaveKey('drain_max_ms')
        ->and($config)->not->toHaveKey('drain_failfast')
        ->and($config)->not->toHaveKey('delivery')
        ->and($config)->not->toHaveKey('agent_fallback');
});
