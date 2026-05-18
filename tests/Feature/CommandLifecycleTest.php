<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Uptimex\Client\Facades\Uptimex;

it('starts a trace on CommandStarting and flushes on CommandFinished', function () {
    event(new CommandStarting('app:do-thing', new ArgvInput(['app']), new BufferedOutput));

    Uptimex::record('command', ['name' => 'app:do-thing']);

    event(new CommandFinished('app:do-thing', new ArgvInput(['app']), new BufferedOutput, 0));

    // Phase 6: the dedicated CommandLifecycleListener also auto-records a
    // `command` event on Finished, so the batch contains the manual + auto entry.
    expect($this->transport->sentBatches())->toHaveCount(1)
        ->and($this->transport->sentBatches()[0]['events'])->toHaveCount(2);
});

it('skips its own commands so they can flush inline', function () {
    event(new CommandStarting('uptimex:test', new ArgvInput(['app']), new BufferedOutput));

    expect(Uptimex::context())->toBeNull();
});

it('skips schedule:run so an idle scheduler emits no telemetry', function () {
    event(new CommandStarting('schedule:run', new ArgvInput(['app']), new BufferedOutput));

    expect(Uptimex::context())->toBeNull();
});
