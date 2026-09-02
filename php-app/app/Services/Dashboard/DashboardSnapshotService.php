<?php

namespace App\Services\Dashboard;

use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\InvoiceService;
use App\Support\Access\TenantScope;

/**
 * Ported from lib/data/repository.ts's getDashboardSnapshot -- the
 * source's own single landing dashboard (app/page.tsx's "VAT
 * transaction control centre"). Genuinely one shape for every actor,
 * not a per-role snapshot dispatch: the only variation is the
 * national-vs-own-taxpayer scoping every other snapshot service in this
 * migration already uses. `recentInvoices` reuses
 * `InvoiceService::list()` directly (matching the source's own reuse of
 * `listInvoices`), not a second, parallel query that could drift from
 * it.
 *
 * `recentAudit` is empty for an actor without `audit:read` -- the
 * source checks this per-field, not as a route-level gate, since a
 * taxpayer-scoped actor legitimately sees their own VAT metrics/
 * invoices without ever seeing the append-only audit stream.
 *
 * Not ported: the source's own `requireLicensedPermission(user,
 * "dashboard:read", { operationClass: "READ" })` combines a permission
 * check with a licensing/entitlement gate (`App\Support\Licensing\
 * EntitlementGate`, used elsewhere in this migration for genuinely
 * organisation-scoped licensed operations -- see
 * AccessGovernanceService/AdministrationSnapshotService). `dashboard:read`
 * is granted unconditionally to every one of this migration's 21 roles
 * (verified against `Permissions::ROLE_PERMISSIONS`), including
 * national-scope roles that resolve to no organisation at all, so the
 * controller checks the permission alone
 * (`$this->authorize('permission', 'dashboard:read')`) rather than
 * routing a landing page through an entitlement gate built for
 * organisation-scoped licensed features.
 */
class DashboardSnapshotService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /**
     * @return array{
     *     metrics: array{invoice_count: int, total_cents: int, tax_cents: int, exception_count: int},
     *     recent_invoices: list<array<string, mixed>>,
     *     recent_audit: list<array{id: string, action: string, resource_type: string, resource_id: string, occurred_at: string}>,
     *     risk_counts: list<array{risk_level: string, count: int}>,
     * }
     */
    public function snapshot(User $actor): array
    {
        $scoped = ! TenantScope::isNational($actor);
        $taxpayerId = $actor->taxpayer_id ?? '__none__';

        $scopedQuery = Invoice::query();
        if ($scoped) {
            $scopedQuery->where(fn ($q) => $q->where('supplier_taxpayer_id', $taxpayerId)->orWhere('customer_taxpayer_id', $taxpayerId));
        }

        $metrics = (clone $scopedQuery)->selectRaw(
            "COUNT(*) as invoice_count, COALESCE(SUM(total_cents),0) as total_cents, ".
            "COALESCE(SUM(tax_cents),0) as tax_cents, SUM(CASE WHEN status='EXCEPTION' THEN 1 ELSE 0 END) as exception_count"
        )->first();

        $riskCounts = (clone $scopedQuery)->selectRaw('risk_level, COUNT(*) as count')->groupBy('risk_level')->get();

        $recentAudit = $actor->hasAppPermission('audit:read')
            ? AuditEvent::orderByDesc('occurred_at')->limit(6)->get()
            : collect();

        return [
            'metrics' => [
                'invoice_count' => (int) ($metrics->invoice_count ?? 0),
                'total_cents' => (int) ($metrics->total_cents ?? 0),
                'tax_cents' => (int) ($metrics->tax_cents ?? 0),
                'exception_count' => (int) ($metrics->exception_count ?? 0),
            ],
            'recent_invoices' => $this->invoices->list($actor, 6),
            'recent_audit' => $recentAudit->map(fn (AuditEvent $e) => [
                'id' => $e->id, 'action' => $e->action, 'resource_type' => $e->resource_type,
                'resource_id' => $e->resource_id, 'occurred_at' => $e->occurred_at->toISOString(),
            ])->values()->all(),
            'risk_counts' => $riskCounts->map(fn ($r) => ['risk_level' => $r->risk_level, 'count' => (int) $r->count])->values()->all(),
        ];
    }
}
