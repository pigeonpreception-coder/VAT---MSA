<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Every write action reached through a Blade form should call this
     * instead of generating `(string) Str::uuid()` inline -- a fresh
     * random key per request silently defeats App\Support\CommandLedger's
     * replay detection, which is the exact bug a red-team pass found
     * across every *ViewController (see docs/MIGRATION_MATRIX.md's
     * "Duplicate-submission hardening" section). The paired
     * <x-idempotency-key /> component renders one stable UUID into a
     * hidden field once per GET request; a double-click or a
     * browser-back-resubmit replays the same already-rendered form, so
     * the same key arrives here and CommandLedger::prior() finds the
     * match. The fallback only fires for a request that genuinely never
     * carried the field (a hand-crafted request, or a JSON API caller
     * hitting this same route directly) -- it deliberately provides zero
     * protection in that case, exactly matching pre-fix behaviour, rather
     * than accepting an attacker-supplied key that could collide with (or
     * be replayed against) another user's own real command.
     */
    protected function formIdempotencyKey(Request $request): string
    {
        $supplied = $request->input('idempotency_key');

        return is_string($supplied) && $supplied !== '' ? $supplied : (string) Str::uuid();
    }
}
