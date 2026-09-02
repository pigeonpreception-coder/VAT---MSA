<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardSnapshotService;
use App\Support\Access\TenantScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own `app/page.tsx` ("VAT transaction control
 * centre") -- see App\Services\Dashboard\DashboardSnapshotService's own
 * doc comment for the full port. Replaces the earlier placeholder
 * (Session/effective-permissions cards only), now driven by the same
 * `getDashboardSnapshot` aggregate the source's landing page uses.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardSnapshotService $dashboard): View
    {
        $this->authorize('permission', 'dashboard:read');
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'isNationalScope' => TenantScope::isNational($user),
            'snapshot' => $dashboard->snapshot($user),
        ]);
    }
}
