<?php

use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Delivery\DirectDispatcher;
use Uptimex\Client\Delivery\SpoolingDispatcher;
use Uptimex\Client\Drain\DrainBudget;
use Uptimex\Client\Drain\SpoolDrainer;
use Uptimex\Client\Spool\FilesystemSpool;
use Uptimex\Client\Spool\SpoolPathResolver;
use Uptimex\Client\Support\FilesystemLock;
use Uptimex\Client\Tests\Doubles\FakeClock;
use Uptimex\Client\Tests\Doubles\FakeTransport;
use Uptimex\Client\Uptimex;

beforeEach(function () {
    $this->dir = freshSpoolDir();
    $this->clock = new FakeClock;
    $this->spool = new FilesystemSpool(new SpoolPathResolver($this->dir), $this->clock);
    $this->fakeTransport = new FakeTransport;
});

afterEach(fn () => deleteDir($this->dir));

function spoolingUptimex($spool, $transport): Uptimex
{
    return new Uptimex(
        config: app('config'),
        dispatcher: new SpoolingDispatcher($spool, new DirectDispatcher($transport)),
    );
}

it('spools a batch on endTrace instead of sending it inline', function () {
    $uptimex = spoolingUptimex($this->spool, $this->fakeTransport);

    $uptimex->startTrace(ExecutionContext::TYPE_REQUEST, ['source' => 'e2e']);
    $uptimex->record('request', ['duration_ms' => 5]);

    expect($uptimex->endTrace('ok'))->toBeTrue()
        ->and($this->spool->size())->toBe(1)         // written to disk
        ->and($this->fakeTransport->sendCalls)->toBe(0); // no network on the request path
});

it('drains the spooled batch to the transport on a later pass', function () {
    $uptimex = spoolingUptimex($this->spool, $this->fakeTransport);
    $uptimex->startTrace(ExecutionContext::TYPE_REQUEST, ['source' => 'e2e']);
    $uptimex->record('request', ['duration_ms' => 5]);
    $uptimex->endTrace('ok');

    $result = (new SpoolDrainer($this->spool, $this->fakeTransport, new FilesystemLock($this->dir), $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($result->sent)->toBe(1)
        ->and($this->fakeTransport->sent)->toHaveCount(1)
        ->and($this->fakeTransport->sent[0]['events'][0]['type'])->toBe('request')
        ->and($this->spool->size())->toBe(0);
});

it('keeps the batch spooled when the first drain fails, then ships it on the next', function () {
    $uptimex = spoolingUptimex($this->spool, $this->fakeTransport);
    $uptimex->startTrace(ExecutionContext::TYPE_REQUEST, ['source' => 'e2e']);
    $uptimex->record('request', ['duration_ms' => 5]);
    $uptimex->endTrace('ok');

    // Ingest is down — the first drain fails.
    (new SpoolDrainer($this->spool, (new FakeTransport)->fail(), new FilesystemLock($this->dir), $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($this->spool->size())->toBe(1); // nothing lost

    // Ingest recovers; past the backoff window the batch ships.
    $this->clock->advance(60);
    $result = (new SpoolDrainer($this->spool, $this->fakeTransport, new FilesystemLock($this->dir), $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($result->sent)->toBe(1)
        ->and($this->spool->size())->toBe(0);
});
