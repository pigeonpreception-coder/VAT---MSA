<?php

namespace App\Http\Controllers\Navigation;

use App\Http\Controllers\Controller;
use App\Services\Navigation\NavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ported from app/api/v1/navigation/{workspace,children,actions,
 * preferences}/route.ts -- Phase 12's portal-navigation slice, the last of
 * `control-plane-repository.ts`'s five sub-domains besides the workflow
 * engine and the rest of Access governance. Every route here requires only
 * the coarse `workspace:read` permission -- the source deliberately gates
 * fine-grained per-item visibility *inside* NavigationService itself
 * (`rowAllowed`), not at the route layer, so a route guard here would be
 * redundant with (and could drift from) the row-level check.
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
}
