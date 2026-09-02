<?php

namespace App\Services\Administration;

use App\Models\Organisation;
use App\Models\User;
use App\Support\Licensing\EntitlementGate;
use App\Support\Licensing\LicenseResolver;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/data/control-plane-repository.ts's
 * getAdministrationSnapshot -- the fixed-list dashboard aggregate every
 * GET-list route across all five of Phase 12's own sub-domain slices
 * (Licensing & Entitlements, Organisation administration & employees,
 * Portal navigation -- not this one, Access governance, and the workflow
 * engine) bundles into instead of a dedicated query of its own. Ten
 * independent reads in the source's own `Promise.all`; run sequentially
 * here since this port has no equivalent parallel-query primitive, exactly
 * matching every other multi-read snapshot already built in this
 * migration (e.g. `LicensingService::usageSnapshot`).
 */
class AdministrationSnapshotService
{
    /** @return array<string, mixed> */
    public function getAdministrationSnapshot(User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation, 'license' => $license] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'READ', 0, $requestedOrganisationId);

        $entitlements = LicenseResolver::getEntitlements($license);

        $employees = DB::table('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('job_titles as j', 'j.id', '=', 'e.job_title_id')
            ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->where('e.organisation_id', $organisation->id)
            ->orderByRaw("CASE e.status WHEN 'ACTIVE' THEN 1 WHEN 'INVITED' THEN 2 ELSE 3 END")
            ->orderBy('e.full_name')
            ->limit(100)
            ->get(['e.id', 'e.employee_number', 'e.full_name', 'e.email', 'e.status', 'e.last_activity_at', 'd.name as department', 'j.name as job_title', 'b.name as branch']);

        $roles = DB::table('organisation_roles as r')
            ->leftJoin('organisation_role_permissions as rp', 'rp.organisation_role_id', '=', 'r.id')
            ->where('r.organisation_id', $organisation->id)
            // Every selected r.* column is listed explicitly, not just
            // r.id, because MariaDB's ONLY_FULL_GROUP_BY mode does not
            // reliably infer functional dependency on a primary key
            // through a table alias -- confirmed live against this
            // environment's real MariaDB, not a portability guess.
            ->groupBy('r.id', 'r.name', 'r.description', 'r.version', 'r.approval_limit_cents', 'r.status', 'r.created_by')
            ->orderBy('r.name')->orderByDesc('r.version')
            ->get(['r.id', 'r.name', 'r.description', 'r.version', 'r.approval_limit_cents', 'r.status', 'r.created_by', DB::raw("COALESCE(GROUP_CONCAT(rp.permission_code SEPARATOR ', '), '') as permissions")]);

        $workflows = DB::table('workflows as w')
            ->leftJoin('workflow_versions as v', 'v.workflow_id', '=', 'w.id')
            ->where('w.organisation_id', $organisation->id)
            ->orderBy('w.name')->orderByDesc('v.version_number')
            ->get(['w.id', 'w.name', 'w.domain_action', 'w.status', 'v.version_number', 'v.status as version_status', 'v.published_at']);

        $tasks = DB::table('workflow_assignments as a')
            ->join('workflow_instances as i', 'i.id', '=', 'a.workflow_instance_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.assigned_user_id')
            ->leftJoin('users as initiator', 'initiator.id', '=', 'i.initiated_by')
            ->where('i.organisation_id', $organisation->id)->where('a.status', 'PENDING')
            ->orderBy('a.due_at')->limit(50)
            ->get(['a.id', 'a.status', 'a.due_at', 'i.resource_type', 'i.resource_id', 'u.name as assigned_to', 'initiator.name as initiated_by']);

        $accessRequests = DB::table('access_requests as r')
            ->join('users as subject', 'subject.id', '=', 'r.subject_user_id')
            ->join('users as requester', 'requester.id', '=', 'r.requested_by')
            ->join('organisation_roles as role', 'role.id', '=', 'r.organisation_role_id')
            ->where('r.organisation_id', $organisation->id)
            ->orderByDesc('r.requested_at')->limit(50)
            ->get(['r.id', 'r.status', 'r.justification', 'r.requested_at', 'subject.name as subject', 'requester.name as requested_by', 'role.name as role_name']);

        $accessReviews = DB::table('access_reviews')->where('organisation_id', $organisation->id)
            ->orderByDesc('due_at')->limit(20)
            ->get(['id', 'name', 'review_type', 'status', 'period_start', 'due_at', 'completed_at']);

        $structures = [
            'departments' => DB::table('departments')->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->count(),
            'business_units' => DB::table('business_units')->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->count(),
            'branches' => DB::table('branches')->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->count(),
            'job_titles' => DB::table('job_titles')->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->count(),
        ];

        $administrators = DB::table('organisation_administrators as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.organisation_id', $organisation->id)
            ->orderByDesc('a.is_primary')->orderBy('u.name')
            ->get(['a.id', 'a.administrator_role_code', 'a.scope', 'a.is_primary', 'a.status', 'u.name as display_name', 'u.email']);

        // security_events genuinely has no organisation_id column at all
        // (a platform-wide table, not tenant-scoped) -- the source's own
        // two subqueries here are unfiltered by organisation for exactly
        // that reason, reproduced faithfully rather than "fixed" into a
        // scoped filter the schema cannot express.
        $security = [
            'security_events_30d' => DB::table('security_events')->where('occurred_at', '>=', now()->subDays(30))->count(),
            'failed_logins_30d' => DB::table('security_events')->where('event_type', 'AUTHENTICATION_FAILED')->where('occurred_at', '>=', now()->subDays(30))->count(),
            'open_sod_violations' => DB::table('sod_violations')->where('organisation_id', $organisation->id)->where('status', 'OPEN')->count(),
        ];

        return [
            'organisation' => $this->presentOrganisation($organisation),
            'license' => array_merge($this->presentLicense($license), ['price' => null, 'pricingConfigured' => false]),
            'entitlements' => $entitlements,
            'employees' => $employees->map(fn ($row) => (array) $row)->all(),
            'roles' => $roles->map(fn ($row) => (array) $row)->all(),
            'workflows' => $workflows->map(fn ($row) => (array) $row)->all(),
            'tasks' => $tasks->map(fn ($row) => (array) $row)->all(),
            'accessRequests' => $accessRequests->map(fn ($row) => (array) $row)->all(),
            'accessReviews' => $accessReviews->map(fn ($row) => (array) $row)->all(),
            'structures' => $structures,
            'administrators' => $administrators->map(fn ($row) => (array) $row)->all(),
            'security' => $security,
            'integrations' => ['payments' => 'DISABLED', 'itas' => 'DISABLED_PENDING_AUTHORITY_CONTRACT', 'statutoryRules' => 'APPROVED_RULES_ONLY'],
        ];
    }

    private function presentOrganisation(Organisation $organisation): array
    {
        return ['id' => $organisation->id, 'taxpayer_id' => $organisation->taxpayer_id, 'legal_name' => $organisation->legal_name];
    }

    /** @return array<string, mixed> */
    private function presentLicense(array $license): array
    {
        return [
            'id' => $license['id'], 'organisation_id' => $license['organisation_id'], 'plan_id' => $license['plan_id'],
            'plan_code' => $license['plan_code'], 'plan_name' => $license['plan_name'], 'plan_version' => (int) $license['plan_version'],
            'state' => $license['state'], 'retention_policy' => $license['retention_policy'],
            'current_period_start' => $license['current_period_start'], 'current_period_end' => $license['current_period_end'],
        ];
    }
}
