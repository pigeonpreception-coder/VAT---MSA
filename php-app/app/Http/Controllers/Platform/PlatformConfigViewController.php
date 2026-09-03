<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\PlatformChangeValidator;
use App\Exceptions\PlatformResourceException;
use App\Exceptions\PlatformValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformChangeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ports the source's own platform config/change-management screen onto
 * App\Services\Platform\PlatformChangeService directly (all 5 methods) --
 * this view adds no query of its own. `config()`/`listChangeRequests()`
 * are read straight from the service; the three "propose a change" forms
 * (one per target type) and the decide/provision writes all go through
 * the service's own maker-checker gate, never a second write path.
 *
 * **provisionStaff wears `password.confirm` at the route level**, unlike
 * ReportViewController's requestExport/approveExport: this command is
 * unconditionally step-up gated in the source (the same posture
 * Administration's employee/role invitations and Licensing's state
 * changes already established), not data-conditional, so there is no
 * need to replicate StepUp::isFresh()'s inline check here -- Laravel's
 * own middleware already refuses an unconfirmed request before this
 * controller is ever reached.
 */
class PlatformConfigViewController extends Controller
{
    public function __construct(private readonly PlatformChangeService $platform) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'platform:read');
        $user = $request->user();
        $status = $request->query('status');
        $status = is_string($status) && trim($status) !== '' ? mb_strtoupper(trim($status)) : null;

        return view('platform.index', [
            'config' => $this->platform->config(),
            'changeRequests' => $this->platform->listChangeRequests($status),
            'statusFilter' => $status,
            'canManage' => $user->hasAppPermission('platform:manage'),
            'staffRoles' => PlatformChangeValidator::PLATFORM_STAFF_ROLES,
        ]);
    }

    public function requestChange(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'platform:manage');
        $targetType = mb_strtoupper((string) $request->input('target_type'));
        $proposedValue = match ($targetType) {
            'FEATURE_FLAG' => ['enabled' => $request->boolean('enabled')],
            'PLATFORM_CONFIG' => ['value' => (string) $request->input('value')],
            'ACCESS_POLICY' => ['parameters' => json_decode((string) $request->input('parameters'), true)],
            default => [],
        };
        $payload = [
            'schema_version' => '1.0.0', 'target_type' => $targetType, 'target_id' => (string) $request->input('target_id'),
            'proposed_value' => $proposedValue, 'reason' => (string) $request->input('reason'),
        ];

        try {
            $this->platform->requestChange($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (PlatformValidationException $e) {
            return redirect()->route('platform.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('platform.index')->withErrors(['change' => $e->getMessage()]);
        }

        return redirect()->route('platform.index')->with('status', 'Change request submitted for independent decision.');
    }

    public function decideChange(Request $request, string $changeRequestId): RedirectResponse
    {
        $this->authorize('permission', 'platform:manage');
        $payload = [
            'schema_version' => '1.0.0', 'decision' => mb_strtoupper((string) $request->input('decision')),
            'notes' => (string) $request->input('notes', 'Decided from the platform console.'),
        ];

        try {
            $this->platform->decideChange($changeRequestId, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (PlatformValidationException $e) {
            return redirect()->route('platform.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('platform.index')->withErrors(['decide' => $e->getMessage()]);
        }

        return redirect()->route('platform.index')->with('status', 'Change request decided.');
    }

    public function provisionStaff(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'platform:manage');
        $payload = [
            'schema_version' => '1.0.0', 'external_user_id' => (string) $request->input('external_user_id'),
            'email' => (string) $request->input('email'), 'display_name' => (string) $request->input('display_name'),
            'role' => mb_strtoupper((string) $request->input('role')),
        ];

        try {
            $this->platform->provisionStaff($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (PlatformValidationException $e) {
            return redirect()->route('platform.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('platform.index')->withErrors(['staff' => $e->getMessage()])->withInput();
        }

        return redirect()->route('platform.index')->with('status', 'Platform staff account provisioned.');
    }
}
