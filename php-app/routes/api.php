<?php

use App\Http\Controllers\Integration\PosInvoiceController;
use App\Http\Controllers\Signup\SignupController;
use App\Http\Middleware\AuthenticatePosApiClient;
use Illuminate\Support\Facades\Route;

/**
 * The one stateless, credential-authenticated API surface in this
 * application. Every other "JSON API" (the api/v1/** group defined
 * inside routes/web.php) is deliberately Blade/session-driven -- see that
 * group's own doc comment -- which a real external system with no
 * browser session, like a taxpayer's own private Point-of-Sale terminal,
 * has no way to participate in.
 *
 * This file is registered via bootstrap/app.php's `api:` routing key,
 * which Laravel auto-prefixes with `/api` and wraps in the stateless
 * `api` middleware group (throttle:api + SubstituteBindings only -- no
 * session, no CSRF), so every route below resolves under `/api/pos/v1/**`.
 *
 * User's own explicit request: a taxpayer's own private POS system
 * "shall be linked up through their API shared" to push invoices in real
 * time, authenticated by a real, taxpayer-issued credential
 * (App\Services\Integration\PosApiClientService) rather than an
 * "awaiting confirmation" stub -- see that service's own doc comment for
 * why this integration, unlike ITAS/E-Tariff, is built to genuinely work
 * today.
 */
Route::middleware(AuthenticatePosApiClient::class)->prefix('pos/v1')->group(function () {
    Route::post('/invoices', [PosInvoiceController::class, 'store']);
});

/**
 * Ported from app/api/v1/signup-applications/route.ts -- the self-serve
 * commercial SaaS signup channel (lib/data/signup-repository.ts's
 * submitSelfServeSignup). The one other genuinely unauthenticated command
 * in this codebase besides the POS credential-authenticated group above:
 * a real anonymous applicant, with no browser session and no taxpayer
 * credential yet, is exactly who this route is for. No middleware beyond
 * this file's own stateless `api` group default (throttle:api +
 * SubstituteBindings) -- SignupService itself calls RateLimitGuard's
 * source/device/email-keyed buckets directly, matching source's own
 * inline enforceSelfServeSignupSourceRateLimits/EmailRateLimit calls
 * rather than a per-route middleware alias.
 */
Route::post('/signup/v1/applications', [SignupController::class, 'store']);
