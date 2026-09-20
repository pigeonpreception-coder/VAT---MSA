<?php

namespace App\Http\Controllers\TaxpayerSystem;

use App\Exceptions\RepositoryConflictException;
use App\Exceptions\TaxpayerSystemResourceException;
use App\Exceptions\TaxpayerSystemValidationException;
use App\Http\Controllers\Controller;
use App\Services\TaxpayerSystem\TaxpayerSystemService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Real Blade UI for TaxpayerSystemService (the NamRA e-VAT MS Registered
 * Taxpayer Systems Framework), alongside the JSON API surface
 * TaxpayerSystemController already exposes -- the source has no page.tsx
 * for this either (JSON-API-only), matching the Security Operations/
 * Payment connector precedent of adding a Blade view anyway.
 *
 * A single list page carries every human-driven action rather than a
 * separate detail page: register (taxpayer's own organisation,
 * taxpayer-systems:manage), approve (national-scope NamRA role,
 * taxpayer-systems:approve, step-up gated), and suspend (the owning
 * taxpayer, taxpayer-systems:manage) -- each row shows only the actions
 * the viewer could actually perform, mirroring RefundViewController's own
 * per-status-and-permission action gating. RecordSynchronization stays
 * JSON-API-only (TaxpayerSystemController::sync) -- it is the taxpayer's
 * own ERP/POS/accounting system reporting its post-sync connectivity
 * state machine-to-machine, not a human clicking a button.
 */
class TaxpayerSystemViewController extends Controller
{
    public function __construct(private readonly TaxpayerSystemService $systems) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'taxpayer-systems:read');
        $user = $request->user();

        return view('taxpayer-systems.index', [
            'registrations' => $this->systems->index($user),
            'canManage' => $user->hasAppPermission('taxpayer-systems:manage'),
            'canApprove' => $user->hasAppPermission('taxpayer-systems:approve'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'taxpayer-systems:manage');

        $payload = [
            'schema_version' => '1.0.0', 'vat_registration_number' => (string) $request->input('vat_registration_number'),
            'tin' => $request->input('tin') ?: null, 'company_registration_number' => $request->input('company_registration_number') ?: null,
            'system_name' => (string) $request->input('system_name'), 'system_vendor' => (string) $request->input('system_vendor'),
            'system_category' => (string) $request->input('system_category'), 'credential_reference' => $request->input('credential_reference') ?: null,
        ];

        try {
            $this->systems->register($payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (TaxpayerSystemValidationException|TaxpayerSystemResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['registration' => $e->getMessage()])->withInput();
        }

        return redirect()->route('taxpayer-systems.index')->with('status', 'System registered as DRAFT, pending NamRA approval.');
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'taxpayer-systems:approve');

        try {
            $this->systems->approve($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (TaxpayerSystemResourceException|RepositoryConflictException|AuthorizationException|TaxpayerSystemValidationException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return redirect()->route('taxpayer-systems.index')->with('status', 'Registration approved.');
    }

    public function suspend(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'taxpayer-systems:manage');

        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        try {
            $this->systems->suspend($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (TaxpayerSystemValidationException|TaxpayerSystemResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['suspension' => $e->getMessage()]);
        }

        return redirect()->route('taxpayer-systems.index')->with('status', 'Registration suspended.');
    }
}
