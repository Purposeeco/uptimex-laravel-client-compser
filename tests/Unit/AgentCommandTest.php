<?php

use Illuminate\Support\Facades\Artisan;

it('uptimex:agent --once boots, runs one pass, and exits cleanly', function () {
    config()->set('uptimex.agent_address', '127.0.0.1:0'); // OS picks a free port

    expect(Artisan::call('uptimex:agent', ['--once' => true]))->toBe(0);
});

it('uptimex:agent reports a bind failure cleanly', function () {
    // Occupy a port, then point the agent at it — the bind must fail.
    $occupied = stream_socket_server('tcp://127.0.0.1:0');
    config()->set('uptimex.agent_address', stream_socket_get_name($occupied, false));

    $exit = Artisan::call('uptimex:agent', ['--once' => true]);

    fclose($occupied);

    expect($exit)->toBe(1);
});
