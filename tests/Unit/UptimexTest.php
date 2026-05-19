<?php

use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Support\AgentGate;

it('is enabled when token is configured', function () {
    expect(Uptimex::isEnabled())->toBeTrue();
});

it('is a no-op when token is not configured', function () {
    config()->set('uptimex.token', '');

    expect(Uptimex::isEnabled())->toBeFalse();
});

it('shouldStartTrace is true when the SDK is enabled and the agent is up', function () {
    AgentGate::seed(true);

    expect(Uptimex::shouldStartTrace())->toBeTrue();
});

it('shouldStartTrace is false when the agent is down — the SDK goes idle', function () {
    AgentGate::seed(false);

    expect(Uptimex::shouldStartTrace())->toBeFalse()
        ->and(Uptimex::isEnabled())->toBeTrue(); // still configured — just gated
});

it('shouldStartTrace is false when the SDK is disabled, regardless of the agent', function () {
    config()->set('uptimex.token', '');
    AgentGate::seed(true);

    expect(Uptimex::shouldStartTrace())->toBeFalse();
});

it('starts a trace and assigns a UUIDv7 id', function () {
    $context = Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    expect($context->traceId)->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($context->type)->toBe('request');
});

it('records events under the active trace', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['duration_ms' => 12]);
    Uptimex::record('query', ['sql_hash' => 'abc']);

    expect(Uptimex::buffer()?->count())->toBe(2);
});

it('does not record events when no trace is active', function () {
    Uptimex::record('request');

    expect(Uptimex::context())->toBeNull();
});

it('does not record events when paused', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    Uptimex::pause();
    Uptimex::record('request');
    expect(Uptimex::buffer()?->count())->toBe(0);

    Uptimex::resume();
    Uptimex::record('request');
    expect(Uptimex::buffer()?->count())->toBe(1);
});

it('nests pause/resume correctly', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    Uptimex::pause();
    Uptimex::pause();
    expect(Uptimex::isPaused())->toBeTrue();

    Uptimex::resume();
    expect(Uptimex::isPaused())->toBeTrue();

    Uptimex::resume();
    expect(Uptimex::isPaused())->toBeFalse();
});

it('ignore() pauses for the duration of the callback', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    Uptimex::ignore(function () {
        Uptimex::record('request');
    });

    expect(Uptimex::buffer()?->count())->toBe(0)
        ->and(Uptimex::isPaused())->toBeFalse();
});

it('ignore() resumes even if the callback throws', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    expect(fn () => Uptimex::ignore(fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect(Uptimex::isPaused())->toBeFalse();
});

it('endTrace flushes events to the transport', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['duration_ms' => 5]);
    Uptimex::record('query', ['sql_hash' => 'abc']);

    $ok = Uptimex::endTrace('ok');

    expect($ok)->toBeTrue();

    $batches = $this->transport->sentBatches();
    expect($batches)->toHaveCount(1)
        ->and($batches[0]['events'])->toHaveCount(2)
        ->and($batches[0])->toHaveKeys(['batch_uuid', 'sdk_version', 'host', 'events']);
});

it('endTrace with empty buffer skips the transport', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    Uptimex::endTrace('ok');

    expect($this->transport->sentBatches())->toBeEmpty();
});

it('endTrace is idempotent', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request');

    Uptimex::endTrace('ok');
    Uptimex::endTrace('ok');

    expect($this->transport->sentBatches())->toHaveCount(1);
});

it('drops oldest events when the buffer is full', function () {
    config()->set('uptimex.event_buffer', 2);

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['idx' => 1]);
    Uptimex::record('request', ['idx' => 2]);
    Uptimex::record('request', ['idx' => 3]);

    expect(Uptimex::buffer()?->count())->toBe(2)
        ->and(Uptimex::buffer()?->dropped())->toBe(1);
});
