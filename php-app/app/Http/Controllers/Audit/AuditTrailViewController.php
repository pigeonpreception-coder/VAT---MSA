<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditChainVerification;
use App\Models\AuditEvent;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Real Blade UI for Module 8 Phase D's GetAuditTrail/VerifyAuditChain
 * (App\Services\Audit\AuditService), alongside the JSON API surface
 * AuditTrailController already exposes -- source has no page.tsx for
 * either (app/audit/page.tsx is a separate, simpler unfiltered read via
 * lib/data/repository.ts's own listAuditEvents, not this filtered/
 * paginated search), matching this migration's own established
 * precedent of adding a Blade view anyway for a genuinely human-facing
 * internal-audit workflow rather than a machine-to-machine one.
 */
class AuditTrailViewController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('permission', 'audit:read');

        $filters = $request->only(['resource_type', 'resource_id', 'action', 'actor_id']);
        $result = AuditService::searchTrail([
            'resource_type' => $filters['resource_type'] ?? null ? mb_strtoupper(trim($filters['resource_type'])) : null,
            'resource_id' => $filters['resource_id'] ?? null,
            'action' => $filters['action'] ?? null ? mb_strtoupper(trim($filters['action'])) : null,
            'actor_id' => $filters['actor_id'] ?? null,
        ]);

        $verifications = AuditService::listChainVerifications(10);
        $lastVerification = $verifications->first();

        // Blade view redesign (2026-09-29): audit_events is a system-wide,
        // potentially large table -- unlike the tenant-scoped compliance
        // lists redesigned earlier in this sweep, these stat tiles and
        // side panels are deliberately computed with cheap DB-level
        // aggregates (COUNT/groupBy against this table's own indexed
        // columns -- see its migration's (resource_type, resource_id) and
        // actor_id indexes) rather than fetching the full table into PHP
        // the way the smaller, tenant-scoped lists' stats were.
        //
        // Deliberately NOT added: an "events by outcome" tile or panel.
        // AuditService::append() -- the only write path into this table
        // (confirmed by reading it) -- hardcodes outcome to 'SUCCESS' for
        // every row it ever writes, so a by-outcome breakdown would
        // always read 100% SUCCESS and carry no signal, the same
        // reasoning that kept a by-status tile off the Disputes redesign.
        $metrics = [
            'total' => AuditEvent::count(),
            'matching' => $result['total_count'],
            'verification_status' => $lastVerification->status ?? null,
            'verification_runs' => AuditChainVerification::count(),
        ];
        $byResourceType = AuditEvent::query()
            ->select('resource_type', DB::raw('count(*) as count'))
            ->groupBy('resource_type')->orderByDesc('count')->limit(10)->get()->all();
        $byActor = AuditEvent::query()
            ->select('actor_id', 'actor_role', DB::raw('count(*) as count'))
            ->groupBy('actor_id', 'actor_role')->orderByDesc('count')->limit(10)->get()->all();

        return view('audit-trail.index', [
            'events' => $result['items'],
            'totalCount' => $result['total_count'],
            'filters' => $filters,
            'verifications' => $verifications,
            'metrics' => $metrics, 'byResourceType' => $byResourceType, 'byActor' => $byActor,
        ]);
    }

    public function verifyChain(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'audit:read');

        $verification = AuditService::runChainVerification($request->user(), (string) Str::uuid());

        $status = $verification->status === 'PASSED'
            ? "Chain verification passed -- {$verification->verified_count} event(s) verified."
            : "Chain verification FAILED at event {$verification->first_break_id} ({$verification->first_break_reason}) -- a CRITICAL security incident has been opened.";

        return redirect()->route('audit-trail.index')->with($verification->status === 'PASSED' ? 'status' : 'chainBreak', $status);
    }
}
