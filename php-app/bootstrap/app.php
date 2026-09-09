<?php

use Illuminate\Database\QueryException;
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

        // Red-team finding RT-001 (VAT-MSA Resilience Audit, 2026-09-09):
        // ExpenseService::create() had a real DB-level unique constraint
        // on (organisation_id, expense_number) but no application-level
        // pre-check ahead of it and no catch for the resulting
        // QueryException -- so a plain sequential resubmission (no
        // concurrency needed) crashed with an uncaught 500 showing the raw
        // SQL. ExpenseService now has its own pre-check (matching
        // QuotationService's own established pattern), and the systemic
        // root cause -- every Blade *ViewController generating a fresh
        // idempotency key per request instead of a stable one, defeating
        // CommandLedger::prior() -- is fixed too (see
        // Controller::formIdempotencyKey() and <x-idempotency-key/>). This
        // handler is the deliberate third layer: the same narrow
        // check-then-insert race the audit flagged as a residual,
        // unreproduced risk for every *other* domain with its own
        // pre-check (quotations, employees, refund claims) still exists
        // in principle under genuine concurrent request processing this
        // single-worker dev server cannot produce -- so rather than trust
        // that no other write path ever hits its own version of this same
        // failure mode, any uncaught duplicate-key violation anywhere in
        // the app now renders as a real, if generic, conflict message
        // instead of a raw SQL error page.
        //
        // SQLSTATE 23000 covers more than a unique-key violation (e.g. a
        // foreign-key violation too), so this only claims "conflict" for
        // the specific MySQL/MariaDB duplicate-entry error code (1062) --
        // any other integrity violation still surfaces as a genuine
        // uncaught exception rather than being mislabeled.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($e->getCode() !== '23000' || ! str_contains($e->getMessage(), 'Duplicate entry')) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'code' => 'CONFLICT',
                    'message' => 'This action conflicts with an existing record. Refresh and try again.',
                ], 409);
            }

            return back()->withErrors(['form' => 'This action conflicts with an existing record -- it may already have been submitted.'])->withInput();
        });
    })->create();
