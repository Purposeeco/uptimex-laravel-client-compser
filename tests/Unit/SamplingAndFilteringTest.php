<?php

use Illuminate\Support\Facades\Context;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;

it('keeps every trace when request_sample_rate is 1.0', function () {
    config()->set('uptimex.request_sample_rate', 1.0);

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['duration_ms' => 5]);

    expect(Uptimex::isPaused())->toBeFalse()
        ->and(Uptimex::sampleRate())->toBe(1.0)
        ->and(Uptimex::buffer()?->count())->toBe(1);
});

it('drops every trace when request_sample_rate is 0.0', function () {
    config()->set('uptimex.request_sample_rate', 0.0);

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['duration_ms' => 5]);
    Uptimex::record('query', ['sql_hash' => 'abc']);

    expect(Uptimex::isPaused())->toBeTrue()
        ->and(Uptimex::sampleRate())->toBe(0.0)
        ->and(Uptimex::buffer()?->count())->toBe(0);
});

it('runtime sample(1.0) un-pauses a sampled-out trace before any events arrive', function () {
    config()->set('uptimex.request_sample_rate', 0.0);

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    expect(Uptimex::isPaused())->toBeTrue();

    Uptimex::sample(1.0);

    Uptimex::record('request', ['duration_ms' => 5]);
    expect(Uptimex::buffer()?->count())->toBe(1)
        ->and(Uptimex::sampleRate())->toBe(1.0);
});

it('clamps runtime sample rate to [0, 1]', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    Uptimex::sample(2.5);
    expect(Uptimex::sampleRate())->toBe(1.0);

    Uptimex::sample(-3.0);
    expect(Uptimex::sampleRate())->toBe(0.0);
});

it('serializes sample_rate into the flushed batch', function () {
    config()->set('uptimex.request_sample_rate', 0.5);

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    // Force-keep so the buffer isn't sampled out (deterministic test).
    Uptimex::sample(1.0);
    Uptimex::record('request', ['duration_ms' => 5]);

    Uptimex::endTrace('ok');

    $batch = $this->transport->sentBatches()[0];
    expect($batch['sample_rate'])->toBe(1.0);
});

it('reject() drops events whose callback returns true', function () {
    Uptimex::rejectQueries(fn (array $payload) => str_starts_with($payload['sql_normalized'] ?? '', 'select * from sessions'));

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('query', ['sql_normalized' => 'select * from sessions where id = ?']);
    Uptimex::record('query', ['sql_normalized' => 'select * from users where id = ?']);

    expect(Uptimex::buffer()?->count())->toBe(1);
});

it('OR-combines multiple reject callbacks', function () {
    Uptimex::rejectMail(fn (array $p) => ($p['subject'] ?? '') === 'A');
    Uptimex::rejectMail(fn (array $p) => ($p['subject'] ?? '') === 'B');

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('mail', ['subject' => 'A']);
    Uptimex::record('mail', ['subject' => 'B']);
    Uptimex::record('mail', ['subject' => 'C']);

    expect(Uptimex::buffer()?->count())->toBe(1);
});

it('does not let a throwing reject callback break ingestion', function () {
    Uptimex::reject('cache', function () {
        throw new RuntimeException('boom');
    });

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('cache', ['key' => 'foo']);

    expect(Uptimex::buffer()?->count())->toBe(1);
});

it('redactHeaders mutates the request headers payload', function () {
    Uptimex::redactHeaders(function (array $headers) {
        $headers['authorization'] = '[redacted]';

        return $headers;
    });

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', [
        'duration_ms' => 5,
        'headers' => ['authorization' => 'Bearer secret', 'user-agent' => 'curl'],
    ]);

    $events = Uptimex::buffer()?->flush() ?? [];
    expect($events[0]['headers']['authorization'])->toBe('[redacted]')
        ->and($events[0]['headers']['user-agent'])->toBe('curl');
});

it('redactQueries rehashes sql_hash when sql_normalized changes', function () {
    Uptimex::redactQueries(fn (string $sql) => preg_replace('/\bemail\b/i', 'redacted', $sql));

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('query', [
        'sql_normalized' => 'select email from users where id = ?',
        'sql_hash' => sha1('select email from users where id = ?'),
    ]);

    $events = Uptimex::buffer()?->flush() ?? [];
    expect($events[0]['sql_normalized'])->toBe('select redacted from users where id = ?')
        ->and($events[0]['sql_hash'])->toBe(sha1('select redacted from users where id = ?'));
});

it('redactLogs mutates the context array', function () {
    Uptimex::redactLogs(function (array $ctx) {
        unset($ctx['secret']);

        return $ctx;
    });

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('log', ['level' => 'info', 'message' => 'hi', 'context' => ['secret' => 'shh', 'user' => 7]]);

    $events = Uptimex::buffer()?->flush() ?? [];
    expect($events[0]['context'])->toBe(['user' => 7]);
});

it('does not let a throwing redact callback break ingestion', function () {
    Uptimex::redact('request', function () {
        throw new RuntimeException('boom');
    });

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['duration_ms' => 5]);

    expect(Uptimex::buffer()?->count())->toBe(1);
});

it('ignore_queries env-var skips query events entirely', function () {
    config()->set('uptimex.ignore_queries', true);

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('query', ['sql_hash' => 'abc']);
    Uptimex::record('cache', ['key' => 'foo']);

    expect(Uptimex::buffer()?->count())->toBe(1);
});

it('ignore_outgoing_requests env-var skips outgoing_request events', function () {
    config()->set('uptimex.ignore_outgoing_requests', true);

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('outgoing_request', ['host' => 'api.stripe.com']);
    Uptimex::record('mail', ['subject' => 'hi']);

    expect(Uptimex::buffer()?->count())->toBe(1);
});

it('snapshots Laravel Context into the flushed batch', function () {
    Context::add('tenant_id', 'tnt-42');
    Context::add('feature_flag', 'beta');

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['duration_ms' => 5]);
    Uptimex::endTrace('ok');

    $batch = $this->transport->sentBatches()[0];
    expect($batch['context'])->toBeArray()
        ->and($batch['context']['tenant_id'])->toBe('tnt-42')
        ->and($batch['context']['feature_flag'])->toBe('beta');
});

it('truncates Context when over context_max_bytes cap', function () {
    config()->set('uptimex.context_max_bytes', 64);

    Context::add('a', str_repeat('x', 50));
    Context::add('b', str_repeat('y', 50));

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    Uptimex::record('request', ['duration_ms' => 5]);
    Uptimex::endTrace('ok');

    $batch = $this->transport->sentBatches()[0];
    expect($batch['context'])->toHaveKey('__truncated', true);
});
