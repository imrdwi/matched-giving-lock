<?php

use App\Support\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A busy pool is a reason to retry, not a server error. The donation
        // was not recorded, so the client can safely send it again.
        $exceptions->render(fn (LockTimeoutException $e) => response()->json(
            ['message' => 'This matching pool is busy. Try again.'],
            409,
            ['Retry-After' => '1'],
        ));
    })->create();
