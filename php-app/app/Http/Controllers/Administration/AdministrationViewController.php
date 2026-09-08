<?php

namespace App\Http\Controllers\Administration;

use App\Exceptions\LicensingValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Administration\AdministrationSnapshotService;
use App\Services\OrganisationAdmin\OrganisationAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/administration/page.tsx +
 * AdministrationActions.tsx -- the "Administration command centre":
 * licence entitlements/usage, employees and employment structure,
 * organisation roles, versioned workflows, access governance, and (this
 * page's only two interactive actions) inviting an employee and creating
 * a least-privilege organisation role. Reuses
 * App\Services\Administration\AdministrationSnapshotService::getAdministrationSnapshot
 * directly for the entire read -- the same fixed-list aggregate every one
 * of Phase 12's own five sub-domain slices already bundles into, so this
 * view adds no query of its own at all, let alone a competing one -- and
 * App\Services\OrganisationAdmin\OrganisationAdminService::inviteEmployee/
 * createOrganisationRole for the two writes, the exact methods
 * App\Http\Controllers\OrganisationAdmin\OrganisationAdminController
 * already serves at /api/v1/organisations/{employees,roles}.
 *
 * One deliberate, documented substitution, not a simplification: the
 * source's own AdministrationActions.tsx gates both actions behind a
 * client-side checkbox ("I completed the local/staging privileged-change
 * step-up check") and a custom `x-vat-msa-local-step-up` header the
 * server trusts blindly -- theatre, not a real check. Both write routes
 * here use the `password.confirm` middleware instead, the same genuine,
 * server-enforced step-up every other sensitive command in this migration
 * already uses (registration decisions, taxpayer suspension, invoice
 * cancellation, VAT-rule approval) -- continuing Phase 6's own precedent
 * of replacing the source's platform-header trust entirely, not just for
 * authentication.
 */
class AdministrationViewController extends Controller
{
    public function __construct(
        private readonly AdministrationSnapshotService $snapshot,
        private readonly OrganisationAdminService $admin,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'administration:read');
        $user = $request->user();

        return view('administration.index', [
            'snapshot' => $this->snapshot->getAdministrationSnapshot($user, $request->query('organisation_id')),
            'canManageEmployees' => $user->hasAppPermission('employees:manage'),
            'canManageRoles' => $user->hasAppPermission('roles:manage'),
        ]);
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'employees:manage');
        $payload = [
            'employee_number' => $request->input('employee_number'), 'full_name' => $request->input('full_name'),
            'email' => $request->input('email'),
        ];

        try {
            $this->admin->inviteEmployee($payload, $request->user(), null);
        } catch (LicensingValidationException|RepositoryConflictException $e) {
            return redirect()->route('administration.index')->withErrors(['employee' => $e->getMessage()])->withInput();
        }

        return redirect()->route('administration.index')->with('status', 'Invitation recorded. External email delivery remains disabled in local staging.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'roles:manage');
        $permissions = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('permissions')))));
        $payload = [
            'name' => $request->input('name'), 'description' => $request->input('description'), 'permissions' => $permissions,
        ];

        try {
            $this->admin->createOrganisationRole($payload, $request->user(), null);
        } catch (LicensingValidationException|RepositoryConflictException $e) {
            return redirect()->route('administration.index')->withErrors(['role' => $e->getMessage()])->withInput();
        }

        return redirect()->route('administration.index')->with('status', 'Role created from the approved permission catalogue.');
    }
}
