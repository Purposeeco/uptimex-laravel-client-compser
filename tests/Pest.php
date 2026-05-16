<?php

use GuzzleHttp\Client;
use Uptimex\Client\Delivery\BatchDispatcher;
use Uptimex\Client\Delivery\DirectDispatcher;
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
    app()->instance(BatchDispatcher::class, new DirectDispatcher($transport));

    return [$url, $token, $transport];
}

/**
 * Build a SpooledBatch for spool / drain / dispatcher tests.
 *
 * @param  list<array<string, mixed>>  $events
 */
function spooledBatch(?string $uuid = null, array $events = [['type' => 'request']]): \Uptimex\Client\Spool\SpooledBatch
{
    return new \Uptimex\Client\Spool\SpooledBatch(
        batchUuid: $uuid ?? bin2hex(random_bytes(16)),
        sdkVersion: '0.1.0',
        host: 'test-host',
        sampleRate: null,
        context: null,
        events: $events,
    );
}

/**
 * A unique, not-yet-created temp directory path for a filesystem test.
 */
function freshSpoolDir(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'uptimex-spool-'.uniqid('', true);
}

/**
 * Recursively delete a directory created by a filesystem test.
 */
function deleteDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
}
