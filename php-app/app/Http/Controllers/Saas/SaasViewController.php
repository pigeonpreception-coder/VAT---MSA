<?php

namespace App\Http\Controllers\Saas;

use App\Exceptions\RepositoryConflictException;
use App\Exceptions\SaasResourceException;
use App\Exceptions\SaasValidationException;
use App\Http\Controllers\Controller;
use App\Services\Saas\SaasService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Real Blade UI for SaasService (Module 10 Phase C: SaaS provider
 * onboarding), alongside the JSON API surface SaasController already
 * exposes -- the source has no page.tsx for this either (JSON-API-only),
 * matching the Security Operations/Payment/Taxpayer Systems precedent of
 * adding a Blade view anyway.
 *
 * A register-and-browse list page (`index`) carries RegisterProvider; a
 * per-provider detail page (`show`) carries the GetUsage read (provider,
 * its one registered application, environment approvals, real
 * integration-connection usage) plus SubmitConformance for that
 * application -- source names no ListProviders command at all, so
 * `SaasService::index()` was added purely to back this list page (see its
 * own doc comment).
 */
class SaasViewController extends Controller
{
    public function __construct(private readonly SaasService $saas) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'developer:read');
        $user = $request->user();

        return view('saas-providers.index', [
            'providers' => $this->saas->index($user),
            'canManage' => $user->hasAppPermission('developer:manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'developer:manage');

        $payload = [
            'schema_version' => '1.0.0', 'provider_key' => (string) $request->input('provider_key'),
            'legal_name' => (string) $request->input('legal_name'), 'contact_email' => (string) $request->input('contact_email'),
            'category' => (string) $request->input('category'),
            'application' => [
                'name' => (string) $request->input('application_name'), 'description' => (string) $request->input('application_description'),
                'requested_capabilities' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('requested_capabilities'))))),
                'endpoint_reference' => (string) $request->input('endpoint_reference'),
            ],
        ];

        try {
            $this->saas->registerProvider($payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (SaasValidationException|SaasResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['registration' => $e->getMessage()])->withInput();
        }

        return redirect()->route('saas-providers.index')->with('status', 'SaaS provider registered.');
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'developer:read');
        $user = $request->user();

        try {
            $usage = $this->saas->getUsage($id, $user);
        } catch (SaasResourceException $e) {
            abort($e->getMessage() === 'SaaS provider was not found.' ? 404 : 422, $e->getMessage());
        }

        return view('saas-providers.show', [
            'usage' => $usage,
            'canManage' => $user->hasAppPermission('developer:manage'),
        ]);
    }

    public function submitConformance(Request $request, string $applicationId): RedirectResponse
    {
        $this->authorize('permission', 'developer:manage');

        $payload = [
            'schema_version' => '1.0.0', 'environment' => (string) $request->input('environment'),
            'tested_capabilities' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('tested_capabilities'))))),
            'acknowledged_events' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('acknowledged_events'))))),
        ];
        $providerId = (string) $request->input('provider_id');

        try {
            $this->saas->submitConformance($applicationId, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (SaasValidationException|SaasResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['conformance' => $e->getMessage()]);
        }

        return redirect()->route('saas-providers.show', $providerId)->with('status', 'Conformance run submitted.');
    }
}
