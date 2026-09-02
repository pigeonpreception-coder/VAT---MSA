<?php

namespace App\Http\Controllers\Navigation;

use App\Http\Controllers\Controller;
use App\Services\Navigation\NavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ported from app/api/v1/navigation/{workspace,children,actions,
 * preferences}/route.ts and app/api/v1/search/route.ts -- Phase 12's
 * Workspace & Navigation domain. workspace/children/actions/preferences
 * require only the coarse `workspace:read` permission -- the source
 * deliberately gates fine-grained per-item visibility *inside*
 * NavigationService itself (`rowAllowed`), not at the route layer, so a
 * route guard here would be redundant with (and could drift from) the
 * row-level check. `search` requires `search:read` instead, matching the
 * source's own separate route.
 */
class NavigationController extends Controller
{
    public function __construct(private readonly NavigationService $navigation) {}

    public function workspace(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workspace:read');

        return response()->json($this->navigation->getEffectiveNavigation($request->user(), $request->query('organisation_id')));
    }

    public function children(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workspace:read');
        $children = $this->navigation->getNavigationChildren(
            $request->user(),
            $request->query('parent_type'),
            $request->query('parent_id'),
            $request->query('organisation_id'),
        );

        return response()->json($children);
    }

    public function actions(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workspace:read');
        $actions = $this->navigation->getNavigationItemActions(
            $request->user(),
            $request->query('item_key'),
            $request->query('organisation_id'),
        );

        return response()->json($actions);
    }

    public function storePreference(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workspace:read');
        $preference = $this->navigation->saveNavigationPreference(
            $request->user(),
            (array) $request->json()->all(),
            $request->query('organisation_id'),
        );

        return response()->json(['preference' => $preference]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorize('permission', 'search:read');
        $query = (string) $request->query('q', '');

        return response()->json(['query' => $query, 'results' => $this->navigation->searchWorkspace($request->user(), $query, $request->query('organisation_id'))]);
    }
}
