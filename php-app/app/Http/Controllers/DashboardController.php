<?php

namespace App\Http\Controllers;

use App\Support\Access\Permissions;
use App\Support\Access\TenantScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Rough Blade equivalent of the source's GetUserAccess (lib/domain/access.ts) landing view. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'isNationalScope' => TenantScope::isNational($user),
            'permissions' => Permissions::effectiveForRole($user->role),
        ]);
    }
}
