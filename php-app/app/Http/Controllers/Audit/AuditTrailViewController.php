<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('audit-trail.index', [
            'events' => $result['items'],
            'totalCount' => $result['total_count'],
            'filters' => $filters,
            'verifications' => AuditService::listChainVerifications(10),
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
