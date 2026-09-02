<?php

namespace App\Services\Compliance;

use App\Models\User;
use App\Support\Access\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/data/compliance-repository.ts's getComplianceSnapshot --
 * the fixed-list dashboard aggregate every other Phase 11 GET-list route
 * (audit cases, obligations, disputes, risk, refunds, communications,
 * notifications) bundles into instead of a dedicated query of its own,
 * matching the same role this migration's other snapshot aggregates
 * already play (see App\Services\Administration\AdministrationSnapshotService
 * and App\Services\Identity\IdentityFoundationSnapshotService). Eleven
 * independent reads in the source's own `Promise.all`; run sequentially
 * here, same as every other multi-read snapshot in this port.
 *
 * `consents`/`delegations` read from two tables (`consent_grants`,
 * `delegations`) that this slice adds purely so this snapshot can read
 * them -- a full-repo grep of the TypeScript source before writing their
 * migrations confirmed neither table is ever written by any command
 * anywhere in it, only by demo seed data, so there is no GrantConsent/
 * CreateDelegation command to port alongside them.
 */
class ComplianceSnapshotService
{
    /** @return array<string, mixed> */
    public function getSnapshot(User $actor): array
    {
        $scoped = ! TenantScope::isNational($actor);
        $taxpayerId = $actor->taxpayer_id ?? '__none__';

        $obligations = $scoped
            ? DB::table('tax_obligations')->where('taxpayer_id', $taxpayerId)->orderByDesc('due_date')->get()
            : DB::table('tax_obligations as o')->join('taxpayers as t', 't.id', '=', 'o.taxpayer_id')
                ->orderByDesc('o.due_date')->limit(200)->get(['o.*', 't.legal_name']);

        $cases = $scoped
            ? DB::table('audit_cases')->where('taxpayer_id', $taxpayerId)->orderByDesc('updated_at')->get()
            : DB::table('audit_cases as c')->join('taxpayers as t', 't.id', '=', 'c.taxpayer_id')
                ->orderByDesc('c.updated_at')->limit(200)->get(['c.*', 't.legal_name', 't.vat_number']);

        $findings = $scoped
            ? DB::table('audit_findings as f')->join('audit_cases as c', 'c.id', '=', 'f.audit_case_id')
                ->where('c.taxpayer_id', $taxpayerId)->orderByDesc('f.created_at')->get(['f.*'])
            : DB::table('audit_findings as f')->join('audit_cases as c', 'c.id', '=', 'f.audit_case_id')
                ->join('taxpayers as t', 't.id', '=', 'c.taxpayer_id')
                ->orderByDesc('f.created_at')->limit(200)->get(['f.*', 'c.case_number', 't.legal_name']);

        $disputes = $scoped
            ? DB::table('disputes')->where('taxpayer_id', $taxpayerId)->orderByDesc('filed_at')->get()
            : DB::table('disputes as d')->join('taxpayers as t', 't.id', '=', 'd.taxpayer_id')
                ->orderByDesc('d.filed_at')->limit(200)->get(['d.*', 't.legal_name']);

        $risks = $scoped
            ? DB::table('risk_indicators')->where('taxpayer_id', $taxpayerId)->orderByDesc('detected_at')->get()
            : DB::table('risk_indicators as r')->join('taxpayers as t', 't.id', '=', 'r.taxpayer_id')
                ->orderByDesc('r.detected_at')->limit(200)->get(['r.*', 't.legal_name']);

        $refunds = $scoped
            ? DB::table('refund_claims as r')
                ->join('vat_return_versions as v', 'v.id', '=', 'r.vat_return_version_id')
                ->join('vat_periods as p', 'p.id', '=', 'v.vat_period_id')
                ->where('r.taxpayer_id', $taxpayerId)->orderByDesc('r.requested_at')
                ->get(['r.*', 'v.version_number', 'p.period_code'])
            : DB::table('refund_claims as r')
                ->join('vat_return_versions as v', 'v.id', '=', 'r.vat_return_version_id')
                ->join('vat_periods as p', 'p.id', '=', 'v.vat_period_id')
                ->join('taxpayers as t', 't.id', '=', 'r.taxpayer_id')
                ->orderByDesc('r.requested_at')->limit(200)
                ->get(['r.*', 'v.version_number', 'p.period_code', 't.legal_name']);

        // The source's own unscoped branch here has no taxpayer join at all
        // (unlike every sibling read above) -- reproduced faithfully rather
        // than "fixed" into an inconsistency the source doesn't have either.
        $refundTransitions = $scoped
            ? DB::table('refund_claim_transitions as rt')->join('refund_claims as r', 'r.id', '=', 'rt.refund_claim_id')
                ->where('r.taxpayer_id', $taxpayerId)->orderByDesc('rt.occurred_at')->get(['rt.*'])
            : DB::table('refund_claim_transitions')->orderByDesc('occurred_at')->limit(200)->get();

        $communications = $scoped
            ? DB::table('communications')->where('taxpayer_id', $taxpayerId)->orderByDesc('occurred_at')->limit(100)->get()
            : DB::table('communications as c')->leftJoin('taxpayers as t', 't.id', '=', 'c.taxpayer_id')
                ->orderByDesc('c.occurred_at')->limit(200)->get(['c.*', 't.legal_name']);

        // The source matches `(user_id=? OR taxpayer_id=?)` when scoped, and
        // `(user_id=? OR 1=1)` -- i.e. unconditionally true -- when national,
        // so a national actor sees every notification regardless of who it
        // targets. Applying the OR clause only when scoped is behaviourally
        // identical without the always-true raw SQL.
        $notificationsQuery = DB::table('notifications')->orderByDesc('created_at')->limit(100);
        if ($scoped) {
            $notificationsQuery->where(function ($query) use ($actor, $taxpayerId) {
                $query->where('user_id', $actor->id)->orWhere('taxpayer_id', $taxpayerId);
            });
        }
        $notifications = $notificationsQuery->get();

        $consents = $scoped
            ? DB::table('consent_grants')->where('taxpayer_id', $taxpayerId)->orderByDesc('created_at')->get()
            : DB::table('consent_grants')->orderByDesc('created_at')->limit(200)->get();

        $delegations = $scoped
            ? DB::table('delegations')->where('taxpayer_id', $taxpayerId)->orderByDesc('created_at')->get()
            : DB::table('delegations')->orderByDesc('created_at')->limit(200)->get();

        return [
            'obligations' => $this->rows($obligations),
            'cases' => $this->rows($cases),
            'findings' => $this->rows($findings),
            'disputes' => $this->rows($disputes),
            'risks' => $this->rows($risks),
            'refunds' => $this->rows($refunds),
            'refundTransitions' => $this->rows($refundTransitions),
            'communications' => $this->rows($communications),
            'notifications' => $this->rows($notifications),
            'consents' => $this->rows($consents),
            'delegations' => $this->rows($delegations),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(Collection $rows): array
    {
        return $rows->map(fn ($row) => (array) $row)->values()->all();
    }
}
