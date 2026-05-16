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
