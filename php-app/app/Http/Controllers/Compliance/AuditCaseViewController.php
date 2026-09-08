<?php

namespace App\Http\Controllers\Compliance;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\ComplianceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\AuditCase;
use App\Models\AuditEvidence;
use App\Models\AuditFinding;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Compliance\AuditCaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Blade UI for AuditCaseService (Module 4 Phases C-D: opening a
 * case, its full lifecycle transition, findings, evidence with custody/
 * legal-hold/integrity-verification, and notes), alongside the JSON API
 * surface AuditCaseController already exposes -- see
 * InvoiceViewController's own doc comment for why this app keeps a
 * dedicated Blade-rendering controller next to each JSON one.
 *
 * Unlike Risk Indicators (never taxpayer-visible at all), an audit case
 * once opened IS taxpayer-visible read-only -- AuditCaseService's own
 * timeline()/evidence()/notes() each explicitly allow the case's own
 * taxpayer, not just a national-scope actor (each throws the same
 * AuthorizationException, and gets the RT-002 clean-403 page, for any
 * *other* out-of-scope actor). Every write action stays officer-only
 * (cases:manage, cases:override-sod for the segregation-of-duties
 * override), enforced both here and independently inside the service.
 *
 * No AuditFinding read method exists on the service at all (only
 * issueFinding, a write) -- confirmed by reading AuditCaseController
 * directly, the same gap Refunds' list endpoint had. show() queries
 * AuditFinding directly, the same "no read method to reuse" precedent
 * RefundViewController and VatLifecycleViewController both already
 * established.
 *
 * Actor display names (who transitioned/found/added/authored what) are
 * resolved via one bulk User::whereIn() lookup in show(), rather than
 * adding an actor()/author() relation to five different models
 * (AuditCaseTransition, AuditFinding, AuditEvidence,
 * AuditEvidenceCustodyEvent, AuditCaseNote) the way RefundClaimTransition
 * got one -- five near-identical relations for one detail page's display
 * need felt like more surface than the alternative.
 */
class AuditCaseViewController extends Controller
{
    public function __construct(private readonly AuditCaseService $cases) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');

        $result = $this->cases->search($request->user(), $request->only('status'));
        $taxpayerIds = collect($result['cases'])->pluck('taxpayer_id')->filter()->unique();
        $taxpayers = Taxpayer::whereIn('id', $taxpayerIds)->get(['id', 'legal_name', 'vat_number'])->keyBy('id');

        $cases = collect($result['cases'])->map(fn (array $case) => $case + [
            'legal_name' => $taxpayers[$case['taxpayer_id']]->legal_name ?? null,
            'vat_number' => $taxpayers[$case['taxpayer_id']]->vat_number ?? null,
        ])->all();

        return view('audit-cases.index', [
            'cases' => $cases,
            'status' => $request->query('status', ''),
            'canManage' => $request->user()->hasAppPermission('cases:manage'),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $case = AuditCase::find($id);
        abort_if(! $case, Response::HTTP_NOT_FOUND);

        // timeline()/evidence()/notes() each independently enforce the same
        // tenant scope (national actor, or this case's own taxpayer) --
        // the first call here is what actually gates this whole page for
        // an out-of-scope actor (a plain AuthorizationException, which
        // gets the RT-002 clean-403 page).
        $timeline = $this->cases->timeline($id, $actor);
        $evidenceResult = $this->cases->evidence($id, $actor);
        $notesResult = $this->cases->notes($id, $actor);
        $findings = AuditFinding::where('audit_case_id', $id)->orderBy('created_at')->get();

        $taxpayer = $case->taxpayer_id ? Taxpayer::find($case->taxpayer_id) : null;
        $actorIds = collect()
            ->merge(collect($timeline['transitions'])->pluck('actor_id'))
            ->merge($findings->pluck('author_id'))
            ->merge(collect($evidenceResult['evidence'])->pluck('added_by'))
            ->merge(collect($evidenceResult['custodyEvents'])->pluck('actor_id'))
            ->merge(collect($notesResult['notes'])->pluck('author_id'))
            ->push($case->opened_by)->push($case->assigned_officer_id)
            ->filter()->unique();
        $actorNames = User::whereIn('id', $actorIds)->pluck('name', 'id');

        $officers = User::whereNull('taxpayer_id')->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'role']);
        $validActions = ComplianceValidator::caseActionsFor($case->status);
        $canManage = $actor->hasAppPermission('cases:manage');
        $requiresSodOverride = $canManage && $case->opened_by === $actor->id;

        return view('audit-cases.show', [
            'case' => $case, 'taxpayer' => $taxpayer, 'transitions' => $timeline['transitions'],
            'findings' => $findings, 'evidence' => $evidenceResult['evidence'], 'custodyEvents' => $evidenceResult['custodyEvents'],
            'notes' => $notesResult['notes'], 'actorNames' => $actorNames, 'officers' => $officers, 'validActions' => $validActions,
            'canManage' => $canManage, 'requiresSodOverride' => $requiresSodOverride,
            'canOverrideSod' => $actor->hasAppPermission('cases:override-sod'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'cases:manage');

        $vatNumber = (string) $request->input('vat_number');
        $taxpayer = Taxpayer::where('vat_number', $vatNumber)->first();
        if (! $taxpayer) {
            return back()->withErrors(['vat_number' => 'No taxpayer is registered with that VAT number.'])->withInput();
        }

        $payload = [
            'schema_version' => '1.0.0', 'taxpayer_id' => $taxpayer->id,
            'case_type' => (string) $request->input('case_type'), 'title' => (string) $request->input('title'),
            'opening_reason' => (string) $request->input('opening_reason'), 'risk_tier' => (string) $request->input('risk_tier'),
        ];

        try {
            $case = $this->cases->open($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('audit-cases.show', $case['id'])->with('status', 'Audit case opened.');
    }

    public function storeTransition(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'cases:manage');

        $payload = [
            'schema_version' => '1.0.0', 'action' => (string) $request->input('action'), 'reason' => (string) $request->input('reason'),
            'officer_id' => $request->input('officer_id'), 'appeal_reference' => $request->input('appeal_reference'),
            'override_reason' => $request->input('override_reason') ?: null,
        ];

        try {
            $this->cases->transition($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('audit-cases.show', $id)->with('status', 'Decision recorded.');
    }

    public function storeFinding(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'cases:manage');

        $payload = [
            'schema_version' => '1.0.0', 'finding_code' => (string) $request->input('finding_code'), 'title' => (string) $request->input('title'),
            'description' => (string) $request->input('description'), 'legal_reference' => $request->input('legal_reference') ?: null,
            'amount_cents' => $this->centsFromDecimal($request->input('amount')), 'currency' => (string) ($request->input('currency') ?: 'NAD'),
            'override_reason' => $request->input('override_reason') ?: null,
        ];

        try {
            $this->cases->issueFinding($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('audit-cases.show', $id)->with('status', 'Finding issued.');
    }

    public function storeEvidence(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'cases:manage');

        $payload = [
            'schema_version' => '1.0.0', 'source_resource_type' => (string) $request->input('source_resource_type'),
            'source_resource_id' => (string) $request->input('source_resource_id'), 'description' => (string) $request->input('description'),
            'checksum_sha256' => $request->input('checksum_sha256') ?: null, 'supersedes_evidence_id' => $request->input('supersedes_evidence_id') ?: null,
        ];

        try {
            $this->cases->addEvidence($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('audit-cases.show', $id)->with('status', 'Evidence added.');
    }

    public function storeEvidenceCustodyEvent(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'cases:manage');

        $evidence = AuditEvidence::find($id);
        abort_if(! $evidence, Response::HTTP_NOT_FOUND);
        $caseId = $evidence->audit_case_id;

        $payload = ['schema_version' => '1.0.0', 'action' => (string) $request->input('action'), 'notes' => $request->input('notes') ?: null];

        try {
            $this->cases->recordEvidenceCustodyEvent($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('audit-cases.show', $caseId)->with('status', 'Custody event recorded.');
    }

    public function storeNote(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'cases:manage');

        $payload = ['schema_version' => '1.0.0', 'body' => (string) $request->input('body'), 'supersedes_note_id' => $request->input('supersedes_note_id') ?: null];

        try {
            $this->cases->addNote($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('audit-cases.show', $id)->with('status', 'Note added.');
    }

    private function centsFromDecimal(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** @return array<string, string> */
    private function fieldErrors(ComplianceValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn (array $error) => [ltrim($error['path'], '/') ?: 'form' => $error['message']])->all();
    }
}
