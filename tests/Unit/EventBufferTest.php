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
