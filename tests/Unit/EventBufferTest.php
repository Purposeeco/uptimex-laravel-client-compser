<?php

use Uptimex\Client\Buffer\EventBuffer;

it('starts empty', function () {
    $buffer = new EventBuffer(10);

    expect($buffer->count())->toBe(0)
        ->and($buffer->isEmpty())->toBeTrue()
        ->and($buffer->dropped())->toBe(0);
});

it('adds events up to capacity', function () {
    $buffer = new EventBuffer(3);

    $buffer->add(['type' => 'request', 'idx' => 1]);
    $buffer->add(['type' => 'request', 'idx' => 2]);
    $buffer->add(['type' => 'request', 'idx' => 3]);

    expect($buffer->count())->toBe(3)
        ->and($buffer->dropped())->toBe(0);
});

it('drops the oldest event when capacity is exceeded', function () {
    $buffer = new EventBuffer(2);

    $buffer->add(['idx' => 1]);
    $buffer->add(['idx' => 2]);
    $buffer->add(['idx' => 3]);

    expect($buffer->count())->toBe(2)
        ->and($buffer->dropped())->toBe(1)
        ->and($buffer->all())->toBe([
            ['idx' => 2],
            ['idx' => 3],
        ]);
});

it('flushes events and resets', function () {
    $buffer = new EventBuffer(5);
    $buffer->add(['idx' => 1]);
    $buffer->add(['idx' => 2]);

    $events = $buffer->flush();

    expect($events)->toHaveCount(2)
        ->and($buffer->count())->toBe(0)
        ->and($buffer->isEmpty())->toBeTrue();
});

it('preserves insertion order after the ring wraps multiple times', function () {
    $buffer = new EventBuffer(3);

    for ($i = 1; $i <= 10; $i++) {
        $buffer->add(['idx' => $i]);
    }

    expect($buffer->count())->toBe(3)
        ->and($buffer->dropped())->toBe(7)
        ->and($buffer->all())->toBe([
            ['idx' => 8],
            ['idx' => 9],
            ['idx' => 10],
        ]);
});

it('flush returns wrapped events in insertion order then resets', function () {
    $buffer = new EventBuffer(2);
    $buffer->add(['idx' => 1]);
    $buffer->add(['idx' => 2]);
    $buffer->add(['idx' => 3]); // wraps — drops idx 1

    expect($buffer->flush())->toBe([['idx' => 2], ['idx' => 3]])
        ->and($buffer->count())->toBe(0)
        ->and($buffer->all())->toBe([]);
});

it('clamps a non-positive capacity to at least one', function () {
    $buffer = new EventBuffer(0);
    $buffer->add(['idx' => 1]);
    $buffer->add(['idx' => 2]);

    expect($buffer->capacity)->toBe(1)
        ->and($buffer->count())->toBe(1)
        ->and($buffer->all())->toBe([['idx' => 2]]);
});
