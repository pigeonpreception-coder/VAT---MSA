<?php

namespace App\Http\Controllers\Reconciliation;

use App\Exceptions\ReconciliationValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Real Blade UI for ReconciliationService (Module 3 Phase A/B: the
 * reconciliation matching engine's NamRA-officer work queue), alongside
 * the JSON API surface ReconciliationController already exposes.
 *
 * Correction (2026-09-23): an earlier version of this doc comment claimed
 * source had no page.tsx for this at all -- true only in the narrow sense
 * that no page calls ReconciliationService's own four command routes
 * (RunMatch/Assign/Resolve/GetWorkQueue) directly. `app/reconciliation/
 * page.tsx` does exist and renders the same `reconciliation_exceptions`
 * data via a separate, simpler, unfiltered read (`lib/data/repository.ts`'s
 * `listExceptions`) -- its own metric-grid totals (open/critical counts,
 * aggregate exception value) were a genuine gap, closed by
 * `ReconciliationService::getSummaryTotals()` below, computed over the
 * full tenant-scoped set independent of the work-queue's own filters
 * (source's page has no filters at all).
 *
 * RunMatch stays JSON-API-only -- source's own doc comment frames it as
 * "the correct per-invoice building block a [future scheduled] job would
 * call", not an officer's own manual per-invoice action; only the two
 * work-queue mutations (Assign/Resolve) get Blade forms here. Both wear
 * `step-up`, matching this migration's own established posture for
 * compliance-write actions (source's own operationClass for both is
 * COMPLIANCE_WRITE).
 */
class ReconciliationViewController extends Controller
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'exceptions:read');
        $user = $request->user();

        return view('exceptions.index', [
            'workQueue' => $this->reconciliation->getWorkQueue($request, $user),
            'summary' => $this->reconciliation->getSummaryTotals($user),
            'canManage' => $user->hasAppPermission('reconciliation:manage'),
            'filters' => $request->only(['status', 'severity', 'assigned_officer_id', 'unassigned_only', 'min_age_days', 'max_age_days']),
        ]);
    }

    public function assign(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'reconciliation:manage');

        try {
            $this->reconciliation->assignException($id, (array) $request->only('officer_id'), $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (ReconciliationValidationException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['assignment' => $e->getMessage()]);
        }

        return redirect()->route('exceptions.index')->with('status', 'Exception assigned.');
    }

    public function resolve(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'reconciliation:manage');

        try {
            $this->reconciliation->resolveException($id, (array) $request->only('notes'), $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (ReconciliationValidationException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['resolution' => $e->getMessage()]);
        }

        return redirect()->route('exceptions.index')->with('status', 'Exception resolved.');
    }
}
