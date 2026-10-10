<?php

use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SchoolContext;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['school' => SchoolContext::class, 'module' => RequireModule::class, 'permission' => RequirePermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The application is an API for its own front end: errors are always JSON.
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (QueryException $e) => DatabaseErrors::response($e));
        // A refusal the schema enforces is expected; any other database error is a fault and is logged.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof QueryException && DatabaseErrors::classify($e) !== null);
    })->create();
