<?php

use Illuminate\Support\Facades\Artisan;

it('generates the supervisor and systemd config files', function () {
    $dir = freshTempDir();

    $exit = Artisan::call('uptimex:install', ['--path' => $dir]);

    expect($exit)->toBe(0)
        ->and(is_file($dir.'/supervisor.conf'))->toBeTrue()
        ->and(is_file($dir.'/uptimex-agent.service'))->toBeTrue();

    deleteDir($dir);
});

it('substitutes the PHP binary, artisan path and agent command into the config', function () {
    $dir = freshTempDir();

    Artisan::call('uptimex:install', ['--path' => $dir]);

    $supervisor = (string) file_get_contents($dir.'/supervisor.conf');
    $systemd = (string) file_get_contents($dir.'/uptimex-agent.service');

    expect($supervisor)->toContain(PHP_BINARY)
        ->and($supervisor)->toContain(base_path('artisan'))
        ->and($supervisor)->toContain('uptimex:agent')
        ->and($systemd)->toContain(PHP_BINARY)
        ->and($systemd)->toContain('uptimex:agent');

    deleteDir($dir);
});

it('reflects the --user option in both generated files', function () {
    $dir = freshTempDir();

    Artisan::call('uptimex:install', ['--path' => $dir, '--user' => 'deploy']);

    expect((string) file_get_contents($dir.'/supervisor.conf'))->toContain('user=deploy')
        ->and((string) file_get_contents($dir.'/uptimex-agent.service'))->toContain('User=deploy');

    deleteDir($dir);
});

it('succeeds even when no token is configured', function () {
    config()->set('uptimex.token', '');
    $dir = freshTempDir();

    expect(Artisan::call('uptimex:install', ['--path' => $dir]))->toBe(0);

    deleteDir($dir);
});
