<?php

use Uptimex\Client\Drain\DrainBudget;
use Uptimex\Client\Drain\SpoolDrainer;
use Uptimex\Client\Spool\FilesystemSpool;
use Uptimex\Client\Spool\SpoolPathResolver;
use Uptimex\Client\Support\FilesystemLock;
use Uptimex\Client\Tests\Doubles\FakeClock;
use Uptimex\Client\Tests\Doubles\FakeTransport;
use Uptimex\Client\Transport\NullTransport;

beforeEach(function () {
    $this->dir = freshSpoolDir();
    $this->clock = new FakeClock;
    $this->spool = new FilesystemSpool(new SpoolPathResolver($this->dir), $this->clock);
    $this->lock = new FilesystemLock($this->dir);
});

afterEach(fn () => deleteDir($this->dir));

it('sends a spooled batch and deletes it on success', function () {
    $this->spool->write(spooledBatch());
    $transport = new FakeTransport;

    $result = (new SpoolDrainer($this->spool, $transport, $this->lock, $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($result->sent)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($transport->sent)->toHaveCount(1)
        ->and($this->spool->size())->toBe(0);
});

it('keeps a batch on disk when the transport fails', function () {
    $this->spool->write(spooledBatch());

    $result = (new SpoolDrainer($this->spool, (new FakeTransport)->fail(), $this->lock, $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($result->sent)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($this->spool->size())->toBe(1); // retained for retry — nothing lost
});

it('does not throw when the transport throws', function () {
    $this->spool->write(spooledBatch());
    $transport = new FakeTransport(succeeds: false, throws: true);

    $result = (new SpoolDrainer($this->spool, $transport, $this->lock, $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($result->failed)->toBe(1)
        ->and($this->spool->size())->toBe(1);
});

it('caps a drain pass at the batch budget', function () {
    foreach (range(1, 10) as $ignored) {
        $this->spool->write(spooledBatch());
        $this->clock->advance(1);
    }
    $transport = new FakeTransport;

    $result = (new SpoolDrainer($this->spool, $transport, $this->lock, $this->clock))
        ->drain(new DrainBudget(maxBatches: 4, maxSeconds: 5.0));

    expect($result->sent)->toBe(4)
        ->and($this->spool->size())->toBe(6);
});

it('stops a pass after the failfast threshold of consecutive failures', function () {
    foreach (range(1, 10) as $ignored) {
        $this->spool->write(spooledBatch());
        $this->clock->advance(1);
    }
    $transport = (new FakeTransport)->fail();

    $result = (new SpoolDrainer($this->spool, $transport, $this->lock, $this->clock, failfast: 3))
        ->drain(new DrainBudget(maxBatches: 20, maxSeconds: 5.0));

    expect($transport->sendCalls)->toBe(3)    // stopped after 3 straight failures
        ->and($result->failed)->toBe(3)
        ->and($this->spool->size())->toBe(10); // every batch still on disk
});

it('does nothing while another drainer holds the lock', function () {
    $this->spool->write(spooledBatch());
    $transport = new FakeTransport;
    $held = $this->lock->tryAcquire('uptimex-drain'); // simulate a concurrent drainer

    $result = (new SpoolDrainer($this->spool, $transport, $this->lock, $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($result->lockContended)->toBeTrue()
        ->and($transport->sendCalls)->toBe(0)
        ->and($this->spool->size())->toBe(1);

    $held->release();
});

it('never drains through a NullTransport', function () {
    $this->spool->write(spooledBatch());

    $result = (new SpoolDrainer($this->spool, new NullTransport, $this->lock, $this->clock))
        ->drain(new DrainBudget(20, 5.0));

    expect($result->sent)->toBe(0)
        ->and($this->spool->size())->toBe(1); // not deleted
});

it('respects a zero time budget', function () {
    $this->spool->write(spooledBatch());
    $transport = new FakeTransport;

    $result = (new SpoolDrainer($this->spool, $transport, $this->lock, $this->clock))
        ->drain(new DrainBudget(maxBatches: 20, maxSeconds: 0.0));

    expect($result->sent)->toBe(0)
        ->and($this->spool->size())->toBe(1);
});
