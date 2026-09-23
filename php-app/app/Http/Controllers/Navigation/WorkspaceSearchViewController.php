<?php

namespace App\Http\Controllers\Navigation;

use App\Http\Controllers\Controller;
use App\Services\Navigation\NavigationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/workspace-search/page.tsx -- reuses
 * App\Services\Navigation\NavigationService::searchWorkspace directly, the
 * exact same permission-aware, tenant-filtered search
 * App\Http\Controllers\Navigation\NavigationController::search already
 * serves as JSON at GET /search. That JSON route existed with no Blade UI
 * anywhere to reach it -- this is that UI, not a second implementation.
 */
class WorkspaceSearchViewController extends Controller
{
    public function __construct(private readonly NavigationService $navigation) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'search:read');
        $query = (string) $request->query('q', '');

        // NavigationService::searchWorkspace already short-circuits to []
        // for a query under 2 characters -- matching NavigationController::
        // search's own unconditional call, not a second length check here
        // that could drift from it.
        $results = $this->navigation->searchWorkspace($request->user(), $query, $request->query('organisation_id'));

        return view('workspace-search.index', ['query' => $query, 'results' => $results]);
    }
}
