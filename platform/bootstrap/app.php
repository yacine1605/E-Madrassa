<?php

use App\Exceptions\Refusal;
use App\Http\Middleware\AccountState;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\Localize;
use App\Http\Middleware\LivewireTransaction;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SchoolContext;
use App\Support\DatabaseErrors;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['account' => AccountState::class, 'school' => SchoolContext::class, 'module' => RequireModule::class, 'permission' => RequirePermission::class]);
        $middleware->web(append: [Localize::class, LivewireTransaction::class]);
        // Every response, the panels' included: they have their own middleware, not the web group's.
        $middleware->append(ContentSecurityPolicy::class);
        // Behind the deployment's TLS proxy (DEPLOY.md): the client's address and scheme come from its headers.
        if ($proxies = env('TRUSTED_PROXIES')) $middleware->trustProxies(at: explode(',', $proxies));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API answers in JSON; the consoles' pages keep their redirects and error pages.
        $api = fn (Request $request) => $request->is('api/*') || $request->expectsJson();
        $exceptions->shouldRenderJsonWhen($api);
        $exceptions->render(fn (QueryException $e) => DatabaseErrors::response($e));
        $exceptions->render(fn (Refusal $e) => response()->json(['message' => $e->getMessage(), 'code' => $e->reason, ...$e->extra], $e->status));
        // The framework's own refusals with a code and in the person's language, like the others (NFR-UX-04, FR-I18-01).
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $api($request) ? Refusal::response('unauthenticated', 401) : null);
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($api) {
            if (!$api($request)) return null;
            // A refusal before the language middleware (an expired form token) still finds the language.
            Localize::resolve($request);
            $status = $e->getStatusCode();
            $code = [403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 413 => 'too_large', 419 => 'page_expired',
                429 => 'too_many_attempts'][$status] ?? ($status >= 500 ? 'server_error' : 'bad_request');
            return Refusal::response($code, $status)->withHeaders($e->getHeaders());
        });
        // A fault: a plain message in the person's language, never the details (NFR-SEC-06).
        $exceptions->render(function (Throwable $e, Request $request) use ($api) {
            if (!$api($request) || config('app.debug') || $e instanceof ValidationException || $e instanceof HttpResponseException) return null;
            Localize::resolve($request);
            return Refusal::response('server_error', 500);
        });
        $exceptions->dontReport(Refusal::class);
        // A refusal the schema enforces is expected; any other database error is a fault and is logged.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof QueryException && DatabaseErrors::classify($e) !== null);
    })->create();
