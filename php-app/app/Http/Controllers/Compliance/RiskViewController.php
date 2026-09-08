<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceResourceException;
use App\Exceptions\ComplianceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\RiskIndicator;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Compliance\RiskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Blade UI for RiskService (Module 4 Phases A-B: risk indicators,
 * review assignment, dismiss/escalate decisions, and on-demand taxpayer
 * evaluation), alongside the JSON API surface RiskController already
 * exposes -- see InvoiceViewController's own doc comment for why this app
 * keeps a dedicated Blade-rendering controller next to each JSON one.
 *
 * Unlike every other module built so far, there is no taxpayer-facing
 * counterpart to any of this at all -- RiskService::restricted() itself
 * documents risk indicators as carrying a NamRA-restricted classification,
 * never taxpayer-visible. Every route and view here is purely officer-
 * facing; `risk:read`/`risk:review` are held only by national-scope roles
 * in this app's RBAC (see Permissions::ROLE_PERMISSIONS), and
 * RiskService's own commands independently enforce
 * `TenantScope::isNational()` regardless of what the controller checks.
 */
class RiskViewController extends Controller
{
    public function __construct(private readonly RiskService $risk) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'risk:read');

        $params = $request->only(['status', 'severity', 'taxpayer_id', 'limit', 'offset']);
        $result = $this->risk->restricted($request->user(), $params);

        $indicators = collect($result['items'])->map(function (array $indicator) {
            $taxpayer = $indicator['subject_type'] === 'TAXPAYER' ? Taxpayer::find($indicator['subject_id']) : null;

            return $indicator + ['legal_name' => $taxpayer?->legal_name, 'vat_number' => $taxpayer?->vat_number];
        })->all();

        return view('risk-indicators.index', [
            'indicators' => $indicators, 'totalCount' => $result['totalCount'],
            'limit' => $result['limit'], 'offset' => $result['offset'],
            'filters' => ['status' => $params['status'] ?? '', 'severity' => $params['severity'] ?? ''],
            'canReview' => $request->user()->hasAppPermission('risk:review'),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'risk:read');

        $indicator = RiskIndicator::find($id);
        abort_if(! $indicator, Response::HTTP_NOT_FOUND);
        $taxpayer = $indicator->subject_type === 'TAXPAYER' ? Taxpayer::find($indicator->subject_id) : null;
        $officers = User::whereNull('taxpayer_id')->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'role']);

        return view('risk-indicators.show', [
            'indicator' => $indicator, 'taxpayer' => $taxpayer, 'officers' => $officers,
            'canReview' => $request->user()->hasAppPermission('risk:review'),
        ]);
    }

    public function storeEvaluation(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'risk:review');

        $vatNumber = (string) $request->input('vat_number');
        $taxpayer = Taxpayer::where('vat_number', $vatNumber)->first();
        if (! $taxpayer) {
            return back()->withErrors(['vat_number' => 'No taxpayer is registered with that VAT number.'])->withInput();
        }

        try {
            $this->risk->evaluate($taxpayer->id, ['schema_version' => '1.0.0'], $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceResourceException $e) {
            return back()->withErrors(['vat_number' => $e->getMessage()])->withInput();
        }

        return redirect()->route('risk-indicators.index', ['taxpayer_id' => $taxpayer->id])
            ->with('status', "Risk evaluated for {$taxpayer->legal_name}. Any newly raised or refreshed indicators are shown below.");
    }

    public function storeAssignment(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'risk:review');

        $payload = ['schema_version' => '1.0.0', 'officer_id' => (string) $request->input('officer_id')];

        try {
            $this->risk->assignReview($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e));
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('risk-indicators.show', $id)->with('status', 'Review assigned.');
    }

    public function storeDecision(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'risk:review');

        $payload = [
            'schema_version' => '1.0.0',
            'decision' => (string) $request->input('decision'),
            'rationale' => (string) $request->input('rationale'),
            'case_type' => (string) $request->input('case_type'),
            'case_title' => (string) $request->input('case_title'),
        ];

        try {
            $this->risk->approveAction($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('risk-indicators.show', $id)->with('status', 'Decision recorded.');
    }

    /** @return array<string, string> */
    private function fieldErrors(ComplianceValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn (array $error) => [ltrim($error['path'], '/') ?: 'form' => $error['message']])->all();
    }
}
