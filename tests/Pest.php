<?php

use GuzzleHttp\Client;
use Uptimex\Client\Tests\TestCase;
use Uptimex\Client\Transport\HttpTransport;
use Uptimex\Client\Transport\Transport;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Integration');

/**
 * Shared bootstrap for the Integration suite. Reads the env-gated ingest
 * URL + token, skips the test if either is missing, then binds a real
 * HttpTransport into the container so any code that resolves Transport
 * gets the real wire path instead of NullTransport from the parent
 * TestCase.
 *
 * @return array{0: string, 1: string, 2: HttpTransport}
 */
function uptimexIntegrationBoot(): array
{
    $url = getenv('UPTIMEX_INTEGRATION_INGEST_URL') ?: ($_ENV['UPTIMEX_INTEGRATION_INGEST_URL'] ?? null);
    $token = getenv('UPTIMEX_INTEGRATION_TOKEN') ?: ($_ENV['UPTIMEX_INTEGRATION_TOKEN'] ?? null);

    if (empty($url) || empty($token)) {
        test()->markTestSkipped(
            'Integration tests require UPTIMEX_INTEGRATION_INGEST_URL and '
            .'UPTIMEX_INTEGRATION_TOKEN. Point them at a live UptimeX tenant.'
        );
    }

    $url = rtrim($url, '/');

    config()->set('uptimex.ingest_url', $url);
    config()->set('uptimex.token', $token);

    $transport = new HttpTransport(
        http: new Client,
        ingestUrl: $url,
        token: $token,
        timeout: 5.0,
        connectTimeout: 2.0,
    );

    app()->instance(Transport::class, $transport);

    return [$url, $token, $transport];
}
