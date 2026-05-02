<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Listeners\QueryListener;

function makeQueryEvent(string $sql, float $time = 5.0, array $bindings = []): QueryExecuted
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('mysql');

    return new QueryExecuted($sql, $bindings, $time, $connection);
}

it('records a query event under the active trace', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    $listener = $this->app->make(QueryListener::class);
    $listener->handle(makeQueryEvent('SELECT * FROM users WHERE id = ?', 12.5, [42]));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['type'])->toBe('query')
        ->and($event['duration_ms'])->toBe(13)
        ->and($event['sql_normalized'])->toBe('SELECT * FROM users WHERE id = ?')
        ->and($event['sql_hash'])->toMatch('/^[0-9a-f]{40}$/');
});

it('strips literals from raw SQL before recording', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    $listener = $this->app->make(QueryListener::class);
    $listener->handle(makeQueryEvent("SELECT * FROM users WHERE id = 5 AND name = 'Alice'"));

    $event = Uptimex::buffer()?->all()[0] ?? null;
    expect($event['sql_normalized'])->toBe('SELECT * FROM users WHERE id = ? AND name = ?');
});

it('does not record when no trace is active', function () {
    $listener = $this->app->make(QueryListener::class);
    $listener->handle(makeQueryEvent('SELECT 1'));

    expect(Uptimex::buffer())->toBeNull();
});
