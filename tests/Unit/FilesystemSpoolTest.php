<?php

use Uptimex\Client\Spool\FilesystemSpool;
use Uptimex\Client\Spool\SpoolPathResolver;
use Uptimex\Client\Tests\Doubles\FakeClock;

beforeEach(function () {
    $this->dir = freshSpoolDir();
    $this->clock = new FakeClock;
    $this->spool = new FilesystemSpool(
        paths: new SpoolPathResolver($this->dir),
        clock: $this->clock,
        maxFiles: 5,
        maxBytes: 524288000,
        retryBaseSeconds: 10,
        retryMaxSeconds: 3600,
    );
});

afterEach(fn () => deleteDir($this->dir));

it('writes a batch and lists it back', function () {
    $this->spool->write(spooledBatch('aaaa1111bbbb2222', [['type' => 'query']]));

    $pending = $this->spool->pending(10);

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->batch->events)->toBe([['type' => 'query']])
        ->and($this->spool->size())->toBe(1);
});

it('returns nothing pending when the spool is empty', function () {
    expect($this->spool->pending(10))->toBe([])
        ->and($this->spool->size())->toBe(0);
});

it('deletes an entry by id', function () {
    $id = $this->spool->write(spooledBatch());
    expect($this->spool->size())->toBe(1);

    $this->spool->delete($id);

    expect($this->spool->size())->toBe(0)
        ->and($this->spool->pending(10))->toBe([]);
});

it('writes each batch to its own file', function () {
    $this->spool->write(spooledBatch());
    $this->spool->write(spooledBatch());
    $this->spool->write(spooledBatch());

    expect($this->spool->size())->toBe(3);
});

it('returns pending entries oldest-first', function () {
    $this->spool->write(spooledBatch('1111111111111111'));
    $this->clock->advance(5);
    $this->spool->write(spooledBatch('2222222222222222'));
    $this->clock->advance(5);
    $this->spool->write(spooledBatch('3333333333333333'));

    $ids = array_map(fn ($entry) => $entry->id, $this->spool->pending(10));

    expect($ids)->toBe(['1111111111111111', '2222222222222222', '3333333333333333']);
});

it('leaves no temp files behind after a write', function () {
    $this->spool->write(spooledBatch());

    $temps = array_filter(
        glob($this->dir.'/*') ?: [],
        fn ($path) => str_contains(basename($path), 'uptmp_'),
    );

    expect($temps)->toBe([]);
});

it('keeps a failed batch on disk but out of the eligible set until backoff elapses', function () {
    $id = $this->spool->write(spooledBatch());

    $entry = $this->spool->pending(10)[0];
    $this->spool->markFailed($entry);

    // Still on disk, but in backoff so not eligible to send yet.
    expect($this->spool->pending(10))->toBe([])
        ->and($this->spool->size())->toBe(1);

    // Past the (max 10s) first-attempt backoff it becomes eligible again.
    $this->clock->advance(20);
    $reready = $this->spool->pending(10);

    expect($reready)->toHaveCount(1)
        ->and($reready[0]->id)->toBe($id)
        ->and($reready[0]->attempts)->toBe(1);
});

it('quarantines a file with a corrupt body instead of crashing pending()', function () {
    @mkdir($this->dir, 0775, true);
    $ts = $this->clock->now()->getTimestamp();
    file_put_contents($this->dir."/{$ts}-{$ts}-0-deadbeefdeadbeef.json", 'not json {');

    expect($this->spool->pending(10))->toBe([])
        ->and(glob($this->dir.'/corrupt/*.json') ?: [])->toHaveCount(1);
});

it('quarantines a file whose name does not parse', function () {
    @mkdir($this->dir, 0775, true);
    file_put_contents($this->dir.'/garbage.json', '{}');

    expect($this->spool->pending(10))->toBe([])
        ->and(glob($this->dir.'/corrupt/*.json') ?: [])->toHaveCount(1);
});

it('drops the oldest file when the file cap is exceeded', function () {
    $first = null;
    for ($i = 1; $i <= 7; $i++) {
        $id = $this->spool->write(spooledBatch(str_pad((string) $i, 16, '0', STR_PAD_LEFT)));
        $first ??= $id;
        $this->clock->advance(1);
    }

    expect($this->spool->size())->toBeLessThanOrEqual(5);

    $ids = array_map(fn ($entry) => $entry->id, $this->spool->pending(20));
    expect($ids)->not->toContain($first);
});
