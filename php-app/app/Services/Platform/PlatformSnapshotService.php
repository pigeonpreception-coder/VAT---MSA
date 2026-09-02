<?php

namespace App\Services\Platform;

use App\Models\User;
use App\Support\Access\TenantScope;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/data/platform-repository.ts's getPlatformSnapshot/
 * getTechnicalPlatformSnapshot/getDocumentCustodySummary/
 * getDeveloperPortalSnapshot -- Module 22's own read-only dashboard
 * aggregates, the same role this migration's other snapshot services
 * already play. Everything else in that source file (offline sync
 * commands, reports/report exports, data products/analytics, platform
 * config/change-management) is a genuinely separate sub-module, still
 * NOT STARTED -- this service is scoped to exactly the four snapshot
 * reads.
 *
 * No Eloquent models exist for the tables these queries touch
 * (`integration_connections`/`api_clients`/`webhook_subscriptions`/
 * `sync_jobs`/`bank_imports`/`payment_instructions`/`offline_devices`/
 * `offline_number_ranges`/`offline_sync_batches`/`offline_conflicts`/
 * `report_definitions`/`report_runs`/`service_components`) -- Phase 4
 * deliberately built their migrations schema-only, with no model or
 * service until a real reader needed one. `DB::table()` throughout,
 * matching `App\Services\Administration\AdministrationSnapshotService`'s
 * own established style for exactly this reason.
 */
class PlatformSnapshotService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /**
     * `App\Http\Controllers\Platform\PlatformSnapshotController::show`
     * routes `SUPER_ADMIN`/`INFRASTRUCTURE_ADMIN` straight to
     * `getTechnicalSnapshot()` before this method is ever called (Security
     * fix 2026-08-27, "finance-data exclusion from technical admin" made
     * structural rather than incidental) -- this method's own `$scoped`
     * branch is therefore only ever reached by a national-scope actor
     * (`$scoped=false`) under every role `platform:read` is currently
     * granted to; the `$scoped=true` branch is preserved faithfully for
     * whichever future role grant reaches it, not dead code to prune.
     *
     * @return array<string, mixed>
     */
    public function getSnapshot(User $actor): array
    {
        $scoped = ! TenantScope::isNational($actor);
        $taxpayerId = $actor->taxpayer_id ?? '__none__';
        $organisation = $scoped ? $this->organisations->resolve($actor, null) : null;
        $orgId = $organisation?->id ?? '__none__';

        $integrations = $scoped
            ? DB::table('integration_connections')->where(fn ($q) => $q->whereNull('organisation_id')->orWhere('organisation_id', $orgId))
                ->orderBy('category')->orderBy('display_name')->get()
            : DB::table('integration_connections')->orderBy('category')->orderBy('display_name')->get();

        $clients = $scoped
            ? DB::table('api_clients')->where('organisation_id', $orgId)->orderBy('name')->get()
            : DB::table('api_clients as c')->join('organisations as o', 'o.id', '=', 'c.organisation_id')->orderBy('c.name')->get(['c.*', 'o.legal_name']);

        $webhooks = $scoped
            ? DB::table('webhook_subscriptions as w')->join('api_clients as c', 'c.id', '=', 'w.api_client_id')->where('c.organisation_id', $orgId)->get(['w.*'])
            : DB::table('webhook_subscriptions')->get();

        $sync = $scoped
            ? DB::table('sync_jobs')->where('organisation_id', $orgId)->orderByDesc('requested_at')->limit(100)->get()
            : DB::table('sync_jobs')->orderByDesc('requested_at')->limit(200)->get();

        $bankImports = $scoped
            ? DB::table('bank_imports')->where('organisation_id', $orgId)->orderByDesc('created_at')->get()
            : DB::table('bank_imports')->orderByDesc('created_at')->limit(200)->get();

        $payments = $scoped
            ? DB::table('payment_instructions')->where('taxpayer_id', $taxpayerId)->orderByDesc('approved_at')->get()
            : DB::table('payment_instructions')->orderByDesc('approved_at')->limit(200)->get();

        $devices = $scoped
            ? DB::table('offline_devices')->where('organisation_id', $orgId)->orderBy('display_name')->get()
            : DB::table('offline_devices as d')->join('organisations as o', 'o.id', '=', 'd.organisation_id')->orderBy('d.display_name')->get(['d.*', 'o.legal_name']);

        $ranges = $scoped
            ? DB::table('offline_number_ranges as r')->join('offline_devices as d', 'd.id', '=', 'r.offline_device_id')->where('d.organisation_id', $orgId)->get(['r.*'])
            : DB::table('offline_number_ranges')->get();

        $batches = $scoped
            ? DB::table('offline_sync_batches as b')->join('offline_devices as d', 'd.id', '=', 'b.offline_device_id')->where('d.organisation_id', $orgId)->orderByDesc('b.received_at')->limit(100)->get(['b.*'])
            : DB::table('offline_sync_batches')->orderByDesc('received_at')->limit(200)->get();

        $conflicts = $scoped
            ? DB::table('offline_conflicts as c')->join('offline_sync_batches as b', 'b.id', '=', 'c.offline_sync_batch_id')
                ->join('offline_devices as d', 'd.id', '=', 'b.offline_device_id')->where('d.organisation_id', $orgId)->orderByDesc('c.created_at')->get(['c.*'])
            : DB::table('offline_conflicts')->orderByDesc('created_at')->limit(200)->get();

        $definitions = DB::table('report_definitions')->where('status', 'ACTIVE')->orderBy('name')->get();

        $runs = $scoped
            ? DB::table('report_runs as r')->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
                ->where('r.organisation_id', $orgId)->orderByDesc('r.requested_at')->limit(100)->get(['r.*', 'd.code', 'd.name'])
            : DB::table('report_runs as r')->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
                ->orderByDesc('r.requested_at')->limit(200)->get(['r.*', 'd.code', 'd.name']);

        // Plain alphabetical DESC on criticality, matching the source's
        // own `ORDER BY criticality DESC` verbatim -- not the severity
        // ordering the column name might suggest (LOW/MEDIUM/HIGH/
        // CRITICAL alphabetised DESC is MEDIUM, LOW, HIGH, CRITICAL, not
        // a true severity sort). Reproduced faithfully, not "fixed".
        $components = DB::table('service_components')->orderByDesc('criticality')->orderBy('display_name')->get();

        $documents = $scoped
            ? DB::table('document_metadata')->where('organisation_id', $orgId)->orderByDesc('uploaded_at')->limit(100)->get()
            : DB::table('document_metadata as d')->join('organisations as o', 'o.id', '=', 'd.organisation_id')->orderByDesc('d.uploaded_at')->limit(200)->get(['d.*', 'o.legal_name']);

        $outbox = DB::table('outbox_events')->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->get();

        return [
            'integrations' => $this->rows($integrations), 'clients' => $this->rows($clients), 'webhooks' => $this->rows($webhooks),
            'syncJobs' => $this->rows($sync), 'bankImports' => $this->rows($bankImports), 'payments' => $this->rows($payments),
            'devices' => $this->rows($devices), 'numberRanges' => $this->rows($ranges), 'batches' => $this->rows($batches),
            'conflicts' => $this->rows($conflicts), 'reportDefinitions' => $this->rows($definitions), 'reportRuns' => $this->rows($runs),
            'components' => $this->rows($components), 'documents' => $this->rows($documents), 'outbox' => $this->rows($outbox),
        ];
    }

    /**
     * Unscoped by design, matching the source exactly -- this is the
     * technical/infrastructure view (component health, aggregate status
     * counts), never a per-organisation one; `SUPER_ADMIN`/
     * `INFRASTRUCTURE_ADMIN` hold no organisation scope to filter by in
     * the first place.
     *
     * @return array<string, mixed>
     */
    public function getTechnicalSnapshot(): array
    {
        $integrations = DB::table('integration_connections')
            ->select('provider_key', 'category', 'display_name', 'capabilities', 'configuration_status', 'operational_status', 'data_classification', 'last_health_check_at', 'last_health_outcome')
            ->orderBy('category')->orderBy('display_name')->get();
        $components = DB::table('service_components')->orderByDesc('criticality')->orderBy('display_name')->get();
        $outbox = DB::table('outbox_events')->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->get();
        $clients = DB::table('api_clients')->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->get();
        $webhooks = DB::table('webhook_subscriptions')->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->get();
        $sync = DB::table('sync_jobs')->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->get();
        $security = DB::table('security_events')->select('severity', DB::raw('COUNT(*) as count'))->groupBy('severity')->get();

        return [
            'integrations' => $this->rows($integrations), 'components' => $this->rows($components), 'outbox' => $this->rows($outbox),
            'apiClients' => $this->rows($clients), 'webhooks' => $this->rows($webhooks), 'syncJobs' => $this->rows($sync),
            'securityEvents' => $this->rows($security),
        ];
    }

    /**
     * Consumed by the source's own `app/portal/buyer/page.tsx` server
     * component rather than a dedicated `app/api/v1/**` route file;
     * exposed as one here anyway, matching this migration's own
     * established convention (see `App\Http\Controllers\Identity\
     * IdentityFoundationController`'s identical precedent). No
     * `organisation_id` override param in the source's own signature --
     * always the actor's own resolved scope, mirrored exactly here.
     *
     * @return array{total: int, quarantined: int, clean: int}
     */
    public function documentCustodySummary(User $actor): array
    {
        $organisation = $this->organisations->resolve($actor, null);
        $result = DB::table('document_metadata')->where('organisation_id', $organisation->id)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status='QUARANTINED' THEN 1 ELSE 0 END) as quarantined, SUM(CASE WHEN scan_status='CLEAN' THEN 1 ELSE 0 END) as clean")
            ->first();

        return ['total' => (int) ($result->total ?? 0), 'quarantined' => (int) ($result->quarantined ?? 0), 'clean' => (int) ($result->clean ?? 0)];
    }

    /**
     * Consumed by the source's own `app/portal/developer/page.tsx`
     * server component; exposed as its own route here too, same
     * convention as `documentCustodySummary` above. A `DEVELOPER_PARTNER`
     * actor with no `taxpayer_id` linked yet short-circuits before ever
     * calling `OrganisationResolver::resolve()` -- that role is not
     * national-scope, so an unlinked partner would otherwise fail the
     * resolver's own "no active taxpayer organisation" check; this is a
     * genuine, legitimate state (signed up, not yet linked), not an
     * error.
     *
     * @return array<string, mixed>
     */
    public function developerPortalSnapshot(User $actor): array
    {
        if ($actor->role === 'DEVELOPER_PARTNER' && $actor->taxpayer_id === null) {
            return ['clients' => [], 'webhooks' => [], 'provisioning' => 'ORGANISATION_LINK_REQUIRED'];
        }
        $organisation = $this->organisations->resolve($actor, null);

        $clients = DB::table('api_clients')->where('organisation_id', $organisation->id)->orderBy('name')
            ->get(['id', 'name', 'client_key', 'scopes', 'status', 'rate_limit_profile', 'last_rotated_at', 'expires_at', 'created_at']);
        $webhooks = DB::table('webhook_subscriptions as w')->join('api_clients as c', 'c.id', '=', 'w.api_client_id')
            ->where('c.organisation_id', $organisation->id)->orderByDesc('w.created_at')
            ->get(['w.id', 'w.api_client_id', 'w.event_types', 'w.endpoint_url', 'w.status', 'w.created_at']);

        return ['clients' => $this->rows($clients), 'webhooks' => $this->rows($webhooks), 'provisioning' => 'ORGANISED_SCOPE'];
    }

    /** @return list<array<string, mixed>> */
    private function rows(Collection $rows): array
    {
        return $rows->map(fn ($row) => (array) $row)->values()->all();
    }
}
