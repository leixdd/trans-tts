<?php

it('uses bun-only frontend commands in composer setup scripts', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    $setup = implode("\n", $composer['scripts']['setup'] ?? []);

    expect($setup)->toContain('bun install')
        ->and($setup)->toContain('bun run build')
        ->and($setup)->not->toMatch('/\bnpm\b/');
});

it('builds the frontend stage with bun in the Dockerfile', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('FROM oven/bun:')
        ->and($dockerfile)->toContain('bun run build')
        ->and($dockerfile)->toContain('dunglas/frankenphp')
        ->and($dockerfile)->toContain('octane:frankenphp')
        ->and($dockerfile)->not->toMatch('/\bnpm install\b/');
});

it('defines frankenphp app queue and scheduler services in compose', function () {
    $compose = file_get_contents(base_path('compose.yaml'));

    expect($compose)->toContain('octane:frankenphp')
        ->and($compose)->toContain('queue:work')
        ->and($compose)->toContain('schedule:work')
        ->and($compose)->toContain('scheduler:');
});

it('schedules hourly translation workflow pruning', function () {
    $console = file_get_contents(base_path('routes/console.php'));

    expect($console)->toContain("Schedule::command('translations:prune')->hourly()");
});

it('registers octane with frankenphp as the default server', function () {
    expect(config('octane.server'))->toBe('frankenphp');
});
