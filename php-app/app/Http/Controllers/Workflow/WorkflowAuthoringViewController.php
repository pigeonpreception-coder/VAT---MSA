<?php

namespace App\Http\Controllers\Workflow;

use App\Exceptions\LicensingValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Administration\AdministrationSnapshotService;
use App\Services\Workflow\WorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Ports the source's own workflow-engine authoring screen onto
 * App\Services\Workflow\WorkflowService directly (all 8 methods --
 * createWorkflowDraft, publishWorkflowVersion, testWorkflowVersion,
 * assignWorkflow, decideWorkflowTask, createDelegation, listDelegations,
 * revokeDelegation). The workflow/task catalogue reused here
 * (`organisation`/`workflows`/`tasks`) is the exact same
 * AdministrationSnapshotService::getAdministrationSnapshot slice
 * WorkflowController::listWorkflows already exposes over JSON -- this
 * view's read-only register was already part of Administration's own
 * page before this slice existed (see docs/MIGRATION_MATRIX.md); this
 * page is what makes the engine's own write commands reachable at all,
 * not a second read path for the same rows.
 *
 * **Definition/context authoring is JSON-textarea, not a visual node
 * editor**: `nodes`/`transitions`/routing `context` are structured lists
 * of typed objects (WorkflowValidator::definition()'s own shape) --
 * building a drag-and-drop graph editor is out of scope for a build-out
 * that has never shipped a line of client-side JavaScript. A JSON
 * textarea, prefilled with a valid minimal example and validated
 * entirely server-side by the same WorkflowValidator every JSON-API
 * caller already goes through, is the same "trust the real validator,
 * don't duplicate its shape checks in the UI" posture Platform config's
 * ACCESS_POLICY `parameters` field already established.
 */
class WorkflowAuthoringViewController extends Controller
{
    public function __construct(
        private readonly WorkflowService $workflows,
        private readonly AdministrationSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'workflows:read');
        $user = $request->user();
        $organisationId = $request->query('organisation_id');
        $snapshot = $this->snapshot->getAdministrationSnapshot($user, $organisationId);
        $resolvedOrganisationId = $snapshot['organisation']['id'];

        $draftVersions = DB::table('workflow_versions as v')->join('workflows as w', 'w.id', '=', 'v.workflow_id')
            ->where('v.organisation_id', $resolvedOrganisationId)->where('v.status', 'DRAFT')
            ->orderBy('w.name')->select('v.id', 'v.version_number', 'w.id as workflow_id', 'w.name as workflow_name')->get();

        $members = DB::table('organisation_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organisation_id', $resolvedOrganisationId)->where('m.status', 'ACTIVE')
            ->select('u.id', 'u.name')->distinct()->orderBy('u.name')->get();

        return view('workflows.index', [
            'organisation' => $snapshot['organisation'], 'workflows' => $snapshot['workflows'], 'tasks' => $snapshot['tasks'],
            'roles' => $snapshot['roles'], 'draftVersions' => $draftVersions, 'members' => $members,
            'delegations' => $this->workflows->listDelegations($user, $organisationId),
            'testResult' => session('workflow_test_result'),
            'canManage' => $user->hasAppPermission('workflows:manage'), 'canDecide' => $user->hasAppPermission('workflows:decide'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $nodes = $this->jsonField($request, 'nodes');
        $transitions = $this->jsonField($request, 'transitions');
        if ($nodes === false || $transitions === false) {
            return redirect()->route('workflows.index')->withErrors(['definition' => 'nodes/transitions must be valid JSON.'])->withInput();
        }
        $payload = ['name' => (string) $request->input('name'), 'domain_action' => (string) $request->input('domain_action'), 'nodes' => $nodes, 'transitions' => $transitions];

        try {
            $this->workflows->createWorkflowDraft($payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('workflows.index')->withErrors(['definition' => $e->getMessage()])->withInput();
        }

        return redirect()->route('workflows.index')->with('status', 'Workflow draft created.');
    }

    public function publish(Request $request, string $versionId): RedirectResponse
    {
        $this->authorize('permission', 'workflows:manage');

        try {
            $this->workflows->publishWorkflowVersion($versionId, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('workflows.index')->withErrors(['publish' => $e->getMessage()]);
        }

        return redirect()->route('workflows.index')->with('status', 'Workflow version published.');
    }

    public function test(Request $request, string $versionId): RedirectResponse
    {
        $this->authorize('permission', 'workflows:read');
        $context = $this->jsonContextField($request, 'context');
        if ($context === false) {
            return redirect()->route('workflows.index')->withErrors(['test' => 'context must be valid JSON.']);
        }

        try {
            $result = $this->workflows->testWorkflowVersion($versionId, ['context' => $context], $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('workflows.index')->withErrors(['test' => $e->getMessage()]);
        }

        return redirect()->route('workflows.index')->with('workflow_test_result', $result);
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $context = $this->jsonContextField($request, 'context');
        if ($context === false) {
            return redirect()->route('workflows.index')->withErrors(['assign' => 'context must be valid JSON.'])->withInput();
        }
        $payload = [
            'domain_action' => (string) $request->input('domain_action'), 'resource_type' => (string) $request->input('resource_type'),
            'resource_id' => (string) $request->input('resource_id'), 'context' => $context,
        ];

        try {
            $this->workflows->assignWorkflow($payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('workflows.index')->withErrors(['assign' => $e->getMessage()])->withInput();
        }

        return redirect()->route('workflows.index')->with('status', 'Workflow instance assigned.');
    }

    public function decide(Request $request, string $assignmentId): RedirectResponse
    {
        $this->authorize('permission', 'workflows:decide');
        $payload = ['decision' => mb_strtoupper((string) $request->input('decision')), 'reason' => (string) $request->input('reason')];

        try {
            $this->workflows->decideWorkflowTask($assignmentId, $payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('workflows.index')->withErrors(['decide' => $e->getMessage()]);
        }

        return redirect()->route('workflows.index')->with('status', 'Workflow task decided.');
    }

    public function storeDelegation(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $payload = [
            'delegator_user_id' => (string) $request->input('delegator_user_id'), 'delegate_user_id' => (string) $request->input('delegate_user_id'),
            'workflow_id' => $request->filled('workflow_id') ? (string) $request->input('workflow_id') : null,
            'effective_from' => $this->toIsoUtc($request->input('effective_from')), 'effective_to' => $this->toIsoUtc($request->input('effective_to')),
            'reason' => (string) $request->input('reason'),
        ];

        try {
            $this->workflows->createDelegation($payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('workflows.index')->withErrors(['delegation' => $e->getMessage()])->withInput();
        }

        return redirect()->route('workflows.index')->with('status', 'Delegation created.');
    }

    public function revokeDelegation(Request $request, string $delegationId): RedirectResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $payload = ['reason' => (string) $request->input('reason')];

        try {
            $this->workflows->revokeDelegation($delegationId, $payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('workflows.index')->withErrors(['revoke' => $e->getMessage()]);
        }

        return redirect()->route('workflows.index')->with('status', 'Delegation revoked.');
    }

    /** Decodes a JSON textarea field; false on malformed JSON (WorkflowValidator then reports a clear shape error for a null/missing value). */
    private function jsonField(Request $request, string $field): mixed
    {
        $raw = trim((string) $request->input($field, ''));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : false;
    }

    /**
     * Same as jsonField(), but an explicit `{}` (a literal empty object,
     * meaning "no routing filters") is normalised to null rather than
     * passed through as `[]` -- PHP's json_decode(..., true) makes an
     * empty JSON object indistinguishable from an empty JSON array, and
     * WorkflowValidator::context()'s own array_is_list() check (vacuously
     * true for []) would otherwise reject it as "context must be an
     * object", the same way an empty-object body would trip up any JSON
     * API caller of this validator. Normalising here, in this method's
     * own one caller-side spot, avoids touching the shared, already-
     * tested validator for a shape every other caller has just never
     * happened to send.
     */
    private function jsonContextField(Request $request, string $field): mixed
    {
        $decoded = $this->jsonField($request, $field);

        return is_array($decoded) && count($decoded) === 0 ? null : $decoded;
    }

    private function toIsoUtc(?string $localDateTime): ?string
    {
        if (! $localDateTime) {
            return null;
        }

        try {
            return Carbon::parse($localDateTime)->utc()->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable) {
            return $localDateTime;
        }
    }
}
