<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSnapshotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/developer/page.tsx -- the API client
 * registry and webhook subscriptions, distinct from `app/portal/developer/
 * page.tsx` (App\Http\Controllers\Portal\DeveloperPortalController, the
 * Developer Portal switchboard destination, backed by its own
 * `PlatformSnapshotService::developerPortalSnapshot()` and carrying the
 * client-creation/credential-rotation write actions). This page is
 * purely read-only -- confirmed by reading it in full, it has no write
 * action anywhere on it -- reusing `PlatformSnapshotService::getSnapshot()`
 * directly for its `clients`/`webhooks`/`outbox` fields, the same
 * aggregate `PlatformSnapshotController::show` and
 * OfflineViewController/IntegrationsViewController already reuse.
 */
class DeveloperViewController extends Controller
{
    public function __construct(private readonly PlatformSnapshotService $platform) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'developer:read');

        return view('developer.index', ['data' => $this->platform->getSnapshot($request->user())]);
    }
}
