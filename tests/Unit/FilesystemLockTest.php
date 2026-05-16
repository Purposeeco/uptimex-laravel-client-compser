<?php

use Uptimex\Client\Support\FilesystemLock;

beforeEach(function () {
    $this->dir = freshSpoolDir();
});

afterEach(fn () => deleteDir($this->dir));

it('acquires a free lock', function () {
    $lock = new FilesystemLock($this->dir);

    expect($lock->tryAcquire('drain'))->not->toBeNull();
});

it('refuses a second holder while the lock is held', function () {
    $lock = new FilesystemLock($this->dir);

    $first = $lock->tryAcquire('drain');
    $second = $lock->tryAcquire('drain');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

it('can be re-acquired after release', function () {
    $lock = new FilesystemLock($this->dir);

    $handle = $lock->tryAcquire('drain');
    expect($handle)->not->toBeNull();
    $handle->release();

    expect($lock->tryAcquire('drain'))->not->toBeNull();
});

it('does not contend across different lock names', function () {
    $lock = new FilesystemLock($this->dir);

    expect($lock->tryAcquire('drain'))->not->toBeNull()
        ->and($lock->tryAcquire('other'))->not->toBeNull();
});

it('has an idempotent release', function () {
    $handle = (new FilesystemLock($this->dir))->tryAcquire('drain');

    $handle->release();
    $handle->release();

    expect(true)->toBeTrue();
});
