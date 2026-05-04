<?php

use Illuminate\Support\Str;

/**
 * Real-wire negative-path tests. Each sends a deliberately-malformed batch
 * and asserts the SDK swallows the resulting 422 (returns false, never
 * throws). The value here isn't testing the SDK — the hermetic 4xx test
 * already covers that. The value is locking in the SERVER's validation
 * surface: if a future schema change accidentally loosens a rule, exactly
 * one of these tests starts passing where it used to fail.
 */
beforeEach(function () {
    [, , $this->httpTransport] = uptimexIntegrationBoot();
});

it('rejects a batch with more than 1000 events (422)', function () {
    $events = [];
    for ($i = 0; $i < 1001; $i++) {
        $events[] = [
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ];
    }

    expect($this->httpTransport->send(['events' => $events]))->toBeFalse();
});

it('rejects an event missing the required occurred_at field (422)', function () {
    expect($this->httpTransport->send([
        'events' => [[
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'duration_ms' => 1,
            // occurred_at deliberately omitted
        ]],
    ]))->toBeFalse();
});

it('rejects an event with a non-uuid trace_id (422)', function () {
    expect($this->httpTransport->send([
        'events' => [[
            'type' => 'request',
            'trace_id' => 'not-a-uuid',
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ]))->toBeFalse();
});

it('rejects an event with an unknown type not in KNOWN_EVENT_TYPES (422)', function () {
    expect($this->httpTransport->send([
        'events' => [[
            'type' => 'definitely_not_a_real_event_type',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ]))->toBeFalse();
});
