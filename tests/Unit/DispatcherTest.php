<?php

use Uptimex\Client\Delivery\DirectDispatcher;
use Uptimex\Client\Delivery\NullDispatcher;
use Uptimex\Client\Delivery\SpoolingDispatcher;
use Uptimex\Client\Spool\FilesystemSpool;
use Uptimex\Client\Spool\Spool;
use Uptimex\Client\Spool\SpooledBatch;
use Uptimex\Client\Spool\SpoolEntry;
use Uptimex\Client\Spool\SpoolPathResolver;
use Uptimex\Client\Tests\Doubles\FakeClock;
use Uptimex\Client\Tests\Doubles\FakeTransport;

beforeEach(function () {
    $this->dir = freshSpoolDir();
});

afterEach(fn () => deleteDir($this->dir));

it('DirectDispatcher sends a batch through the transport', function () {
    $transport = new FakeTransport;

    $accepted = (new DirectDispatcher($transport))->dispatch(spooledBatch());

    expect($accepted)->toBeTrue()
        ->and($transport->sent)->toHaveCount(1);
});

it('DirectDispatcher reports failure when the transport rejects the batch', function () {
    $accepted = (new DirectDispatcher((new FakeTransport)->fail()))->dispatch(spooledBatch());

    expect($accepted)->toBeFalse();
});

it('DirectDispatcher skips an empty batch without sending', function () {
    $transport = new FakeTransport;

    $accepted = (new DirectDispatcher($transport))->dispatch(spooledBatch(events: []));

    expect($accepted)->toBeTrue()
        ->and($transport->sendCalls)->toBe(0);
});

it('SpoolingDispatcher writes a batch to the spool instead of sending inline', function () {
    $spool = new FilesystemSpool(new SpoolPathResolver($this->dir), new FakeClock);
    $transport = new FakeTransport;

    $accepted = (new SpoolingDispatcher($spool, new DirectDispatcher($transport)))
        ->dispatch(spooledBatch());

    expect($accepted)->toBeTrue()
        ->and($spool->size())->toBe(1)
        ->and($transport->sendCalls)->toBe(0);
});

it('SpoolingDispatcher falls back to a direct send when the spool write fails', function () {
    $brokenSpool = new class implements Spool
    {
        public function write(SpooledBatch $batch): string
        {
            throw new RuntimeException('disk is read-only');
        }

        public function pending(int $limit): array
        {
            return [];
        }

        public function delete(string $id): void {}

        public function markFailed(SpoolEntry $entry): void {}

        public function size(): int
        {
            return 0;
        }
    };
    $transport = new FakeTransport;

    $accepted = (new SpoolingDispatcher($brokenSpool, new DirectDispatcher($transport)))
        ->dispatch(spooledBatch());

    expect($accepted)->toBeTrue()
        ->and($transport->sent)->toHaveCount(1); // degraded to direct, never dropped
});

it('NullDispatcher accepts a batch and does nothing', function () {
    expect((new NullDispatcher)->dispatch(spooledBatch()))->toBeTrue();
});
