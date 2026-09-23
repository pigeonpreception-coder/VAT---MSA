<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSnapshotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/integrations/page.tsx -- the
 * integration registry and service component posture. Purely read-only,
 * same reasoning as OfflineViewController (the source page has no write
 * action on it at all; the write side already exists separately at
 * IntegrationConnectionController's own JSON routes).
 *
 * `SUPER_ADMIN`/`INFRASTRUCTURE_ADMIN` route to
 * `PlatformSnapshotService::getTechnicalSnapshot()` exactly like the
 * source's own role check and like `PlatformSnapshotController::show`
 * already does -- reusing that same constant rather than re-declaring
 * the role list a third time.
 */
class IntegrationsViewController extends Controller
{
    private const TECHNICAL_ONLY_ROLES = ['SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN'];

    public function __construct(private readonly PlatformSnapshotService $platform) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'integrations:read');
        $user = $request->user();

        $data = in_array($user->role, self::TECHNICAL_ONLY_ROLES, true)
            ? $this->platform->getTechnicalSnapshot()
            : $this->platform->getSnapshot($user);

        return view('integrations.index', ['data' => $data]);
    }
}
