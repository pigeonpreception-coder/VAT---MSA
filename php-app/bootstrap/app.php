<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Red team finding RT-002 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md):
        // a plain Illuminate\Auth\Access\AuthorizationException -- thrown
        // both by TenantScope::requireTaxpayer() and every controller's
        // own $this->authorize() gate denial -- fell through to Laravel's
        // default exception handler, which leaks a full stack trace and
        // local filesystem path whenever APP_DEBUG=true.
        //
        // Type-hinted against AccessDeniedHttpException, not
        // AuthorizationException itself: Laravel's own
        // Handler::prepareException() already converts a status-less
        // AuthorizationException into an AccessDeniedHttpException *before*
        // any registered render() callback is consulted, so a closure
        // type-hinted to AuthorizationException never matches (confirmed --
        // an earlier version of this fix using that type hint silently
        // never ran, and Laravel's own default per-status "errors.403" view
        // convention rendered instead, without the $message this closure
        // intended to pass). AccessDeniedHttpException::getMessage()
        // already carries the original AuthorizationException's message
        // through, since Laravel constructs it as
        // `new AccessDeniedHttpException($e->getMessage(), $e)`.
        //
        // This callback runs before the debug-dependent default path, so
        // the clean output is now guaranteed regardless of APP_DEBUG,
        // matching every one of this app's own custom exceptions
        // (PlatformResourceException and friends), which already render
        // cleanly on their own.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'code' => 'FORBIDDEN',
                    'message' => $e->getMessage(),
                ], 403);
            }

            return response()->view('errors.403', ['message' => $e->getMessage()], 403);
        });
    })->create();
