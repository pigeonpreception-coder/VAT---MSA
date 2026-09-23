<?php

namespace App\Http\Controllers\Security;

use App\Exceptions\RepositoryConflictException;
use App\Exceptions\SecurityResourceException;
use App\Exceptions\SecurityValidationException;
use App\Http\Controllers\Controller;
use App\Services\Security\SecurityOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from lib/data/security-repository.ts's SOC queue/incident
 * commands (getSOCQueue/getIncidentDetail/createIncident/containIncident/
 * revokeIncidentAccess/closeIncident) -- closes out IMPLEMENTATION.md's
 * own claimed "Security Operations view" alongside
 * App\Support\Security\RateLimitGuard/SecurityEventRecorder's rate-limit
 * and detection-rule side. Fills nav-security (NavigationSeeder's own
 * pre-existing `href: '/security'`, `required_permission: 'security:read'`
 * item), which had no route behind it in this migration until now.
 *
 * The source also exposes these as a separate JSON API surface
 * (app/api/v1/security/**), now ported too as
 * App\Http\Controllers\Security\SecurityOperationsController -- see that
 * class's own doc comment for why the "no other Blade-driven admin page
 * has one either" reasoning this comment used to give stopped holding
 * once Fixed Assets and Logistics (the two sibling examples it cited)
 * shipped their own JSON APIs the same session.
 */
class SecurityOperationsViewController extends Controller
{
    public function __construct(private readonly SecurityOperationsService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'security:read');
        $user = $request->user();

        return view('security.operations', [
            'incidents' => $this->service->getSOCQueue($request->query('status'), $request->query('severity')),
            'events' => $this->service->getRecentEvents(),
            'metrics' => $this->service->getOperationsMetrics(),
            'statusFilter' => $request->query('status'),
            'severityFilter' => $request->query('severity'),
            'canManage' => $user->hasAppPermission('security:manage'),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'security:manage');
        $payload = [
            'schema_version' => '1.0.0', 'title' => $request->input('title'), 'severity' => $request->input('severity'),
            'source_event_id' => $request->input('source_event_id') ?: null, 'subject_user_id' => $request->input('subject_user_id') ?: null,
            'details' => $request->input('details'),
        ];

        try {
            $this->service->createIncident($request->user(), $payload, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (SecurityValidationException|RepositoryConflictException|SecurityResourceException $e) {
            return redirect()->route('security.operations')->withErrors(['incident' => $e->getMessage()])->withInput();
        }

        return redirect()->route('security.operations')->with('status', 'Security incident opened.');
    }

    public function contain(Request $request, string $incident): RedirectResponse
    {
        $this->authorize('permission', 'security:manage');
        $payload = ['schema_version' => '1.0.0', 'notes' => $request->input('notes')];

        try {
            $this->service->containIncident($incident, $request->user(), $payload, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (SecurityValidationException|RepositoryConflictException|SecurityResourceException $e) {
            return redirect()->route('security.operations')->withErrors(['contain' => $e->getMessage()])->withInput();
        }

        return redirect()->route('security.operations')->with('status', 'Incident contained.');
    }

    public function revokeAccess(Request $request, string $incident): RedirectResponse
    {
        $this->authorize('permission', 'security:manage');
        $payload = ['schema_version' => '1.0.0', 'notes' => $request->input('notes')];

        try {
            $this->service->revokeIncidentAccess($incident, $request->user(), $payload, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (SecurityValidationException|RepositoryConflictException|SecurityResourceException $e) {
            return redirect()->route('security.operations')->withErrors(['revoke' => $e->getMessage()])->withInput();
        }

        return redirect()->route('security.operations')->with('status', 'Subject user\'s active sessions were revoked.');
    }

    public function close(Request $request, string $incident): RedirectResponse
    {
        $this->authorize('permission', 'security:manage');
        $payload = ['schema_version' => '1.0.0', 'resolution_notes' => $request->input('resolution_notes')];

        try {
            $this->service->closeIncident($incident, $request->user(), $payload, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (SecurityValidationException|RepositoryConflictException|SecurityResourceException $e) {
            return redirect()->route('security.operations')->withErrors(['close' => $e->getMessage()])->withInput();
        }

        return redirect()->route('security.operations')->with('status', 'Incident closed.');
    }
}
