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

it('defaults the delivery mode to spool in the published config', function () {
    $source = file_get_contents(__DIR__.'/../../config/uptimex.php');

    expect($source)->toContain("env('UPTIMEX_DELIVERY', 'spool')");
});

it('defines the spool and drain keys with their documented defaults', function () {
    $config = require __DIR__.'/../../config/uptimex.php';

    expect($config)->toHaveKeys([
        'delivery', 'spool_path', 'spool_max_files', 'spool_max_bytes',
        'drain_auto', 'drain_max_batches', 'drain_max_ms', 'drain_failfast',
        'retry_base_seconds', 'retry_max_seconds',
    ])
        ->and($config['drain_auto'])->toBeTrue()
        ->and($config['spool_max_files'])->toBe(10000)
        ->and($config['spool_max_bytes'])->toBe(524288000)
        ->and($config['drain_max_batches'])->toBe(20)
        ->and($config['retry_max_seconds'])->toBe(3600);
});
