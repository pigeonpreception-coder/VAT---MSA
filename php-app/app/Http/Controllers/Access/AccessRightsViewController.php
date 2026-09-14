<?php

namespace App\Http\Controllers\Access;

use App\Exceptions\AccessRightsValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Models\User;
use App\Models\UserRoleScopeGrant;
use App\Services\Access\UserRoleScopeGrantService;
use App\Support\Access\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin "grant a user an access right" screen -- user's own
 * explicit request. See App\Services\Access\UserRoleScopeGrantService's
 * own doc comment for exactly what a grant does and does not enforce.
 */
class AccessRightsViewController extends Controller
{
    public function __construct(private readonly UserRoleScopeGrantService $grants) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'access-rights:read');
        // Defense-in-depth (2026-09-14 authorization-isolation audit):
        // this listing has no query-level tenant scope of its own -- it
        // shows every user and grant system-wide, relying entirely on
        // access-rights:read being held only by national-scope roles.
        // See UserRoleScopeGrantService's own doc comment for why that
        // assumption is asserted directly here too, not just implied by
        // the permission map.
        abort_unless(TenantScope::isNational($request->user()), 403);

        return view('access-rights.index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'taxpayer_id']),
            'roles' => AccessRole::where('status', 'ACTIVE')->orderBy('name')->get(),
            'grants' => UserRoleScopeGrant::with(['user', 'role', 'grantedBy', 'revokedBy'])
                ->orderByDesc('granted_at')->limit(100)->get(),
            'canManage' => $request->user()->hasAppPermission('access-rights:manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'access-rights:manage');
        $payload = [
            'user_id' => (string) $request->input('user_id'),
            'role_code' => (string) $request->input('role_code'),
            'scope_level' => (string) $request->input('scope_level'),
            'scope_label' => $request->input('scope_label'),
        ];

        try {
            $this->grants->grant($payload, $request->user(), $this->formIdempotencyKey($request));
        } catch (AccessRightsValidationException $e) {
            return redirect()->route('access-rights.index')
                ->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (RepositoryConflictException $e) {
            return redirect()->route('access-rights.index')->withErrors(['user_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('access-rights.index')->with('status', 'Access right granted.');
    }

    public function revoke(Request $request, UserRoleScopeGrant $grant): RedirectResponse
    {
        $this->authorize('permission', 'access-rights:manage');

        try {
            $this->grants->revoke($grant, $request->user());
        } catch (RepositoryConflictException $e) {
            return redirect()->route('access-rights.index')->withErrors(['revoke' => $e->getMessage()]);
        }

        return redirect()->route('access-rights.index')->with('status', 'Access right revoked.');
    }
}
