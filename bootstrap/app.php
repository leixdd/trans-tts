<?php

use App\Http\Middleware\EnsureAnonymousVisitor;
use App\Services\AnonymousVisitor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // App-level Crypt encryption is applied in AnonymousVisitor; avoid double encryption.
        $middleware->encryptCookies(except: [
            AnonymousVisitor::COOKIE_NAME,
        ]);

        $middleware->web(append: [
            EnsureAnonymousVisitor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
