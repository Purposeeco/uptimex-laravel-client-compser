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

it('keeps the agent retry bounds out of env reach', function () {
    // retry_base/retry_max govern load against UptimeX's ingest, not the
    // host app — they are fixed constants, never env-tunable.
    $source = file_get_contents(__DIR__.'/../../config/uptimex.php');

    expect($source)->not->toContain('UPTIMEX_RETRY_BASE')
        ->and($source)->not->toContain('UPTIMEX_RETRY_MAX');
});

it('defaults the delivery mode to direct in the published config', function () {
    $source = file_get_contents(__DIR__.'/../../config/uptimex.php');

    expect($source)->toContain("env('UPTIMEX_DELIVERY', 'direct')");
});

it('defines the agent delivery keys with their documented defaults', function () {
    $config = require __DIR__.'/../../config/uptimex.php';

    expect($config)->toHaveKeys([
        'delivery', 'agent_address', 'agent_connect_timeout_ms',
        'agent_max_queue', 'agent_ship_batch_size', 'agent_fallback',
        'retry_base_seconds', 'retry_max_seconds',
    ])
        ->and($config['agent_address'])->toBe('127.0.0.1:9237')
        ->and($config['agent_connect_timeout_ms'])->toBe(50)
        ->and($config['agent_max_queue'])->toBe(10000)
        ->and($config['agent_ship_batch_size'])->toBe(20)
        ->and($config['agent_fallback'])->toBeTrue()
        ->and($config['retry_base_seconds'])->toBe(5)
        ->and($config['retry_max_seconds'])->toBe(300);
});

it('drops the retired spool and drain config keys', function () {
    $config = require __DIR__.'/../../config/uptimex.php';

    expect($config)->not->toHaveKey('spool_path')
        ->and($config)->not->toHaveKey('spool_max_files')
        ->and($config)->not->toHaveKey('spool_max_bytes')
        ->and($config)->not->toHaveKey('drain_auto')
        ->and($config)->not->toHaveKey('drain_max_batches')
        ->and($config)->not->toHaveKey('drain_max_ms')
        ->and($config)->not->toHaveKey('drain_failfast');
});
