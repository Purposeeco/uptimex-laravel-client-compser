<?php

use Uptimex\Client\Support\AgentGate;

// The global beforeEach in Pest.php seeds the gate "up" so trace-driving tests
// pass; these tests need a clean slate to exercise the breaker itself.
beforeEach(fn () => AgentGate::reset());

it('probes on the first call and returns the verdict', function () {
    $probes = 0;

    $up = AgentGate::isAgentUp(function () use (&$probes) {
        $probes++;

        return true;
    }, 30, now: 1000.0);

    expect($up)->toBeTrue()
        ->and($probes)->toBe(1);
});

it('does not re-probe within the recheck window', function () {
    $probes = 0;
    $probe = function () use (&$probes) {
        $probes++;

        return true;
    };

    AgentGate::isAgentUp($probe, 30, now: 1000.0);
    AgentGate::isAgentUp($probe, 30, now: 1010.0); // 10s later — within the window
    AgentGate::isAgentUp($probe, 30, now: 1029.0); // 29s — still within

    expect($probes)->toBe(1);
});

it('re-probes once the recheck window has elapsed', function () {
    $probes = 0;
    $probe = function () use (&$probes) {
        $probes++;

        return true;
    };

    AgentGate::isAgentUp($probe, 30, now: 1000.0);
    AgentGate::isAgentUp($probe, 30, now: 1031.0); // 31s later — window elapsed

    expect($probes)->toBe(2);
});

it('self-heals — a down verdict flips to up once the window elapses', function () {
    $healthy = false;
    $probe = function () use (&$healthy) {
        return $healthy;
    };

    expect(AgentGate::isAgentUp($probe, 30, now: 1000.0))->toBeFalse();

    $healthy = true; // the agent comes back

    expect(AgentGate::isAgentUp($probe, 30, now: 1005.0))->toBeFalse()   // cached "down"
        ->and(AgentGate::isAgentUp($probe, 30, now: 1031.0))->toBeTrue(); // re-probed
});

it('never throws when the probe throws — falls back to the last verdict', function () {
    AgentGate::seed(true, now: 1000.0);

    $result = AgentGate::isAgentUp(function () {
        throw new \RuntimeException('socket exploded');
    }, 30, now: 1031.0);

    expect($result)->toBeTrue(); // the last known verdict
});

it('assumes the agent is down when the probe throws before any verdict exists', function () {
    $result = AgentGate::isAgentUp(function () {
        throw new \RuntimeException('socket exploded');
    }, 30, now: 1000.0);

    expect($result)->toBeFalse();
});

it('seed() forces a verdict without probing', function () {
    $probes = 0;
    AgentGate::seed(false, now: 1000.0);

    $up = AgentGate::isAgentUp(function () use (&$probes) {
        $probes++;

        return true;
    }, 30, now: 1010.0);

    expect($up)->toBeFalse()
        ->and($probes)->toBe(0);
});

it('reset() clears the cached verdict so the next call probes again', function () {
    AgentGate::seed(true, now: 1000.0);
    AgentGate::reset();

    $probes = 0;
    AgentGate::isAgentUp(function () use (&$probes) {
        $probes++;

        return true;
    }, 30, now: 1010.0);

    expect($probes)->toBe(1);
});
