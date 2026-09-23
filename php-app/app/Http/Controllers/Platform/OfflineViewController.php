<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSnapshotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/offline/page.tsx -- device registry,
 * pre-allocated number ranges and batch intake for Module 22's
 * offline-invoicing continuity. Purely read-only: the source page itself
 * has no write action anywhere on it (confirmed by reading it in full --
 * its own copy says "Batch validation is available through the versioned
 * API"), so there is no form here either, matching this build-out's own
 * "match the source page's own scope" precedent already established by
 * DocumentViewController.
 *
 * Reuses `PlatformSnapshotService::getSnapshot()` directly for its
 * `devices`/`numberRanges`/`batches`/`conflicts` fields -- the exact same
 * aggregate `PlatformSnapshotController::show` already serves at
 * GET /api/v1/platform, not a second parallel query.
 */
class OfflineViewController extends Controller
{
    public function __construct(private readonly PlatformSnapshotService $platform) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'offline:read');

        return view('offline.index', ['data' => $this->platform->getSnapshot($request->user())]);
    }
}
