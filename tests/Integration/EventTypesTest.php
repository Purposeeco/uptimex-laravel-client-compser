<?php

use Illuminate\Support\Str;

/**
 * Per-event-type real-wire tests. Each of the 11 event types declared by
 * IngestBatchRequest::KNOWN_EVENT_TYPES gets one round-trip against the
 * live ingest endpoint. Catches schema drift between the SDK and the
 * server's validator — a mismatch here is the most common silent failure
 * mode in production (server logs `validation_failed`, SDK logs nothing
 * useful, telemetry just stops appearing).
 */
beforeEach(function () {
    [$this->ingestUrl, , $this->httpTransport] = uptimexIntegrationBoot();
});

it('accepts an event of type %s', function (string $type, array $extra) {
    $traceId = (string) Str::orderedUuid();

    $accepted = $this->httpTransport->send([
        'batch_uuid' => (string) Str::uuid(),
        'events' => [array_merge([
            'type' => $type,
            'trace_id' => $traceId,
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ], $extra)],
    ]);

    expect($accepted)->toBeTrue(
        "Server rejected a `{$type}` event (trace_id={$traceId}). ".
        "If KNOWN_EVENT_TYPES on the server changed or the validator added ".
        "a new required field, this is the canary. Inspect `uptimex.transport.*` ".
        'log lines on the consumer and the server response body.'
    );
})->with([
    'request' => ['request', [
        'method' => 'GET',
        'route' => '/integration/events/request',
        'status' => 200,
    ]],
    'query' => ['query', [
        'connection' => 'mysql',
        'sql' => 'select * from users where id = ?',
        'bindings' => [1],
    ]],
    'exception' => ['exception', [
        'class' => RuntimeException::class,
        'message' => 'integration-test exception',
        'file' => __FILE__,
        'line' => __LINE__,
    ]],
    'job' => ['job', [
        'name' => 'App\\Jobs\\IntegrationProbeJob',
        'queue' => 'default',
        'connection' => 'sync',
        'status' => 'processed',
    ]],
    'cache' => ['cache', [
        'operation' => 'hit',
        'key' => 'integration:cache:probe',
        'store' => 'array',
    ]],
    'log' => ['log', [
        'level' => 'info',
        'message' => 'integration-test log line',
        'context' => ['probe' => true],
    ]],
    'mail' => ['mail', [
        'mailer' => 'log',
        'subject' => 'Integration probe',
        'to' => ['test@example.com'],
    ]],
    'notification' => ['notification', [
        'class' => 'App\\Notifications\\IntegrationProbeNotification',
        'channel' => 'mail',
        'notifiable' => 'App\\Models\\User:1',
    ]],
    'command' => ['command', [
        'name' => 'integration:probe',
        'exit_code' => 0,
    ]],
    'scheduled_task' => ['scheduled_task', [
        'expression' => '* * * * *',
        'description' => 'integration:probe scheduled tick',
    ]],
    'outgoing_request' => ['outgoing_request', [
        'method' => 'GET',
        'url' => 'https://example.com/probe',
        'status' => 200,
    ]],
]);
