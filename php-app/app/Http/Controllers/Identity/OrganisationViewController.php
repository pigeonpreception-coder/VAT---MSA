<?php

namespace App\Http\Controllers\Identity;

use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\AssignMembershipRequest;
use App\Http\Requests\Identity\CreateBranchRequest;
use App\Http\Requests\Identity\SuspendTaxpayerRequest;
use App\Http\Requests\Identity\UpdateBranchRequest;
use App\Models\User;
use App\Services\Identity\BranchService;
use App\Services\Identity\IdentityFoundationSnapshotService;
use App\Services\Identity\MembershipService;
use App\Services\Identity\OrganisationService;
use App\Services\Identity\TaxpayerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Real Blade UI bundling Module 1's own smallest, most tightly-coupled
 * services -- OrganisationService, BranchService, MembershipService,
 * TaxpayerService, and IdentityFoundationSnapshotService (49-166 lines
 * each) -- into one coherent module, alongside the JSON API surface
 * OrganisationController/BranchController/MembershipController/
 * TaxpayerController/IdentityFoundationController already expose. Built as
 * one slice deliberately, not five: splitting these into five separate
 * one-service PRs would have fragmented what a user actually experiences
 * as a single screen (an organisation's own profile: its taxpayer record,
 * branches, staff memberships, trading capabilities), the same "don't
 * fragment one coherent page" reasoning already applied to Audit Cases
 * (620 lines, kept as one slice for the same reason in reverse).
 *
 * `identity:read` is held broadly (almost every role in
 * Permissions::ROLE_PERMISSIONS), so the organisations list/detail stays
 * readable widely; `organisations:manage` (branch create/update, membership
 * assignment) is held by an organisation's own TAXPAYER_OWNER/ADMIN as well
 * as PILOT_ADMIN/NAMRA_SYSTEM_ADMIN -- self-service organisation
 * administration, not officer-only, confirmed against the permission map
 * before writing any UI. `taxpayers:suspend` is rarer still (PILOT_ADMIN/
 * NAMRA_SYSTEM_ADMIN only) and already carries its own step-up
 * requirement -- the `password.confirm` middleware already registered on
 * this app's JSON `/taxpayers/{id}/suspension` and
 * `/organisations/{id}/memberships` routes (RT-002/RT-005's own
 * ConfirmPasswordController) is applied identically here, so no new re-auth
 * plumbing was needed, just the same middleware on the same two write
 * routes.
 *
 * `AssignMembershipRequest::ASSIGNABLE_ROLES` intentionally excludes NamRA/
 * platform/portal roles (its own doc comment: granting those here would be
 * a privilege-escalation path). Membership assignment here resolves a
 * target user by email rather than the JSON API's raw `user_id`, so it
 * can't reuse `AssignMembershipRequest` for validation the way branch
 * create/update do -- the role allowlist is therefore enforced directly
 * against that same public constant in `storeMembership()` below, not
 * duplicated as a fresh list that could drift from it.
 *
 * Deliberately out of scope: `RegistrationService`'s own submit/decide
 * commands (a taxpayer/organisation doesn't exist until an approved
 * registration materialises it -- genuinely its own workflow, and decide()
 * touches the still-deferred ITAS integration point) and
 * `OrganisationAdminController::storeCapability` (Phase 12, a different
 * service). Both are read-only here: the snapshot's registrations list and
 * an organisation's trading capabilities render, but neither gets a write
 * action in this slice.
 */
class OrganisationViewController extends Controller
{
    public function __construct(
        private readonly OrganisationService $organisations,
        private readonly BranchService $branches,
        private readonly MembershipService $memberships,
        private readonly TaxpayerService $taxpayers,
        private readonly IdentityFoundationSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'identity:read');
        $actor = $request->user();

        return view('organisations.index', [
            'organisations' => $this->organisations->list($actor),
            'snapshot' => $this->snapshot->getSnapshot($actor),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'identity:read');
        $actor = $request->user();

        // get() throws AuthorizationException (the RT-002 clean-403 page)
        // for an out-of-scope organisation that genuinely exists, and
        // returns null only when it doesn't exist at all -- the same
        // service-level-exception precedent already established for VAT
        // Returns and Audit Cases, not the self-built pre-scoped-query
        // 404 Invoices/Disputes/Obligations use, since OrganisationService
        // already has its own dedicated single-read method here.
        $organisation = $this->organisations->get($actor, $id);
        abort_if(! $organisation, 404);

        return view('organisations.show', [
            'organisation' => $organisation,
            'canManage' => $actor->hasAppPermission('organisations:manage'),
            'canSuspend' => $actor->hasAppPermission('taxpayers:suspend'),
            'assignableRoles' => AssignMembershipRequest::ASSIGNABLE_ROLES,
        ]);
    }

    public function storeBranch(CreateBranchRequest $request, string $organisation): RedirectResponse
    {
        $this->authorize('permission', 'organisations:manage');

        try {
            $this->branches->create($request->user(), $organisation, $request->validated(), (string) Str::uuid());
        } catch (RepositoryConflictException|AuthorizationException|ValidationException $e) {
            return back()->withErrors($this->messageFrom($e))->withInput();
        }

        return redirect()->route('organisations.show', $organisation)->with('status', 'Branch created.');
    }

    public function updateBranch(UpdateBranchRequest $request, string $organisation, string $branch): RedirectResponse
    {
        $this->authorize('permission', 'organisations:manage');

        try {
            $this->branches->update($request->user(), $organisation, $branch, $request->validated(), (string) Str::uuid());
        } catch (AuthorizationException|ValidationException $e) {
            return back()->withErrors($this->messageFrom($e))->withInput();
        }

        return redirect()->route('organisations.show', $organisation)->with('status', 'Branch updated.');
    }

    public function storeMembership(Request $request, string $organisation): RedirectResponse
    {
        $this->authorize('permission', 'organisations:manage');
        $actor = $request->user();

        $email = (string) $request->input('email');
        $targetUser = User::where('email', $email)->first();
        if (! $targetUser) {
            return back()->withErrors(['email' => 'No user is registered with that email address.'])->withInput();
        }

        $roleCode = mb_strtoupper((string) $request->input('role_code'));
        if (! in_array($roleCode, AssignMembershipRequest::ASSIGNABLE_ROLES, true)) {
            return back()->withErrors(['role_code' => 'That role cannot be assigned here.'])->withInput();
        }

        $assignment = ['user_id' => $targetUser->id, 'role_code' => $roleCode, 'branch_id' => $request->input('branch_id') ?: null];

        try {
            $this->memberships->assign($actor, $organisation, $assignment, (string) Str::uuid());
        } catch (RepositoryConflictException|AuthorizationException|ValidationException $e) {
            return back()->withErrors($this->messageFrom($e))->withInput();
        }

        return redirect()->route('organisations.show', $organisation)->with('status', "Membership assigned to {$targetUser->name}.");
    }

    public function storeSuspension(SuspendTaxpayerRequest $request, string $organisation): RedirectResponse
    {
        $this->authorize('permission', 'taxpayers:suspend');

        $taxpayerId = (string) $request->input('taxpayer_id');
        $this->taxpayers->suspend($request->user(), $taxpayerId, $request->validated('reason'), (string) Str::uuid());

        return redirect()->route('organisations.show', $organisation)->with('status', 'Taxpayer suspended.');
    }

    /** @return array<string, string|list<string>> */
    private function messageFrom(RepositoryConflictException|AuthorizationException|ValidationException $e): array
    {
        return $e instanceof ValidationException ? $e->errors() : ['form' => $e->getMessage()];
    }
}
