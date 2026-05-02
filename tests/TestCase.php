<?php

namespace Uptimex\Client\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Uptimex\Client\Transport\NullTransport;
use Uptimex\Client\Transport\Transport;
use Uptimex\Client\UptimexServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Replace HttpTransport with the in-memory NullTransport so tests can
     * assert what would have been sent without opening a socket.
     */
    protected NullTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [UptimexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        config()->set('uptimex.enabled', true);
        config()->set('uptimex.token', 'utx_test');
        config()->set('uptimex.ingest_url', 'https://ingest.test');
        config()->set('uptimex.event_buffer', 500);

        $this->transport = new NullTransport;

        $app->instance(Transport::class, $this->transport);
    }
}
