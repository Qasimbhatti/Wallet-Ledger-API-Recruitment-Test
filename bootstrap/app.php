<?php

use App\Http\ApiException;
use App\Http\Middleware\AuthenticateBearerToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The API is a handful of JSON endpoints, so the default global stack
        // (CORS, proxies, string trimming, maintenance mode...) is replaced by
        // exactly one middleware: bearer-token auth. Being global, it runs
        // before routing, so even unknown URLs answer 401 without a token and
        // nothing touches the database before the token is checked.
        $middleware->use([AuthenticateBearerToken::class]);
        $middleware->group('api', []);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport([ApiException::class]);
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e, Request $request) => ApiException::from($e)->toResponse());
    })->create();
