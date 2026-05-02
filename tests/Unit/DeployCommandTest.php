<?php

use Illuminate\Support\Facades\Artisan;

it('posts a deploy via the transport when configured', function () {
    Artisan::call('uptimex:deploy', [
        'reference' => 'sha-abc',
        '--name' => 'v2.5.0',
        '--url' => 'https://example.com/release',
        '--metadata' => ['env=production', 'committer=alice'],
    ]);

    $deploys = $this->transport->sentDeploys();
    expect($deploys)->toHaveCount(1);

    $payload = $deploys[0];
    expect($payload['reference'])->toBe('sha-abc')
        ->and($payload['name'])->toBe('v2.5.0')
        ->and($payload['url'])->toBe('https://example.com/release')
        ->and($payload['metadata'])->toBe(['env' => 'production', 'committer' => 'alice'])
        ->and($payload['deployed_at'])->toBeString();
});

it('reports failure when SDK is unconfigured', function () {
    config()->set('uptimex.token', '');

    $code = Artisan::call('uptimex:deploy', ['reference' => 'sha-bad']);
    expect($code)->toBe(1)
        ->and($this->transport->sentDeploys())->toBeEmpty();
});

it('skips malformed --metadata pairs', function () {
    Artisan::call('uptimex:deploy', [
        'reference' => 'sha-meta',
        '--metadata' => ['malformed', 'good=value'],
    ]);

    $payload = $this->transport->sentDeploys()[0];
    expect($payload['metadata'])->toBe(['good' => 'value']);
});
