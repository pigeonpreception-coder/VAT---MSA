<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\LicensingValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Administration\AdministrationSnapshotService;
use App\Services\OrganisationAdmin\OrganisationAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/operations/human-resources/page.tsx +
 * HumanResourcesActions.tsx -- Operations > Human Resources Module (NamRA
 * e-VAT MS master prompt section 16E). Reuses
 * App\Services\Administration\AdministrationSnapshotService::
 * getAdministrationSnapshot for the employee directory read (the same
 * fixed-list aggregate the Administration command centre already bundles
 * into, matching the source's own reuse) and
 * App\Services\OrganisationAdmin\OrganisationAdminService::inviteEmployee/
 * terminateEmployee for the two writes -- the exact same commands
 * App\Http\Controllers\Administration\AdministrationViewController and
 * App\Http\Controllers\OrganisationAdmin\OrganisationAdminController
 * already serve, not a second, competing write path. Linking an invited
 * employee to a login identity remains an Administration action, exactly
 * as the source's own page description says.
 */
class HumanResourcesViewController extends Controller
{
    public function __construct(
        private readonly AdministrationSnapshotService $snapshot,
        private readonly OrganisationAdminService $admin,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'employees:read');
        $user = $request->user();
        $snapshot = $this->snapshot->getAdministrationSnapshot($user, $request->query('organisation_id'));

        return view('operations.human-resources.index', [
            'employees' => $snapshot['employees'],
            'structures' => $snapshot['structures'],
            'canManage' => $user->hasAppPermission('employees:manage'),
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
            return redirect()->route('operations.human-resources')->withErrors(['employee' => $e->getMessage()])->withInput();
        }

        return redirect()->route('operations.human-resources')->with('status', 'Invitation recorded. External email delivery remains disabled in local staging.');
    }

    public function terminateEmployee(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'employees:manage');

        try {
            $this->admin->terminateEmployee($id, (string) $request->input('reason'), $request->user(), null);
        } catch (LicensingValidationException|RepositoryConflictException $e) {
            return redirect()->route('operations.human-resources')->withErrors(['employee' => $e->getMessage()]);
        }

        return redirect()->route('operations.human-resources')->with('status', 'Employee terminated.');
    }
}
