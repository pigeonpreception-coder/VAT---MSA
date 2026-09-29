<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceResourceException;
use App\Exceptions\ComplianceValidationException;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Taxpayer;
use App\Services\Compliance\DisputeService;
use App\Support\Access\TaxpayerScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Blade UI for DisputeService (filing and viewing disputes), alongside
 * the JSON API surface DisputeController already exposes -- see
 * InvoiceViewController's own doc comment for why this app keeps a
 * dedicated Blade-rendering controller next to each JSON one.
 *
 * Unlike every other compliance module built so far (Risk Indicators:
 * officer-only; Audit Cases: officer-initiated, taxpayer-visible read-only),
 * this one is taxpayer-INITIATED -- DisputeService::file()'s own doc
 * comment is explicit that, unlike obligations, "a taxpayer may self-file
 * a dispute against their own case/finding/return/decision," and
 * `disputes:manage` is genuinely held by taxpayer roles in this app's RBAC,
 * not just officer ones. The filing form below reflects that directly: a
 * taxpayer-scoped actor never sees a taxpayer picker at all (their own
 * scope is implicit, exactly like the service itself defaults it), while a
 * national-scope actor filing on a taxpayer's behalf sees a VAT-number
 * field, mirroring the picker already used on Risk Indicators/Audit Cases.
 *
 * No read/decide path exists on DisputeService at all beyond file()/
 * search() -- confirmed by reading DisputeController directly. The
 * `disputes` table's own status/assigned_officer_id/decided_at/
 * decision_summary columns exist in the schema but nothing in this
 * migration's application code ever writes to them beyond the initial
 * 'FILED' row -- a genuine, confirmed gap (not introduced by this UI),
 * flagged in docs/MIGRATION_MATRIX.md rather than papered over with a
 * decide action the backend can't actually perform.
 */
class DisputeViewController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $params = $request->only('status');
        $result = $this->disputes->search($actor, $params);

        // Blade view redesign (2026-09-29): search() applies its status
        // filter at the DB level and is tenant-scoped independently of the
        // controller (see its own doc comment) -- the new stat tiles and
        // side panels below need the FULL scoped list to summarize
        // correctly, not just whatever the status filter matched, so this
        // re-fetches unfiltered only when a filter is actually active. Both
        // calls share search()'s existing 100-row cap.
        $all = empty($params['status']) ? $result['disputes'] : $this->disputes->search($actor, [])['disputes'];

        $taxpayerIds = collect($result['disputes'])->merge($all)->pluck('taxpayer_id')->filter()->unique();
        $taxpayers = Taxpayer::whereIn('id', $taxpayerIds)->get(['id', 'legal_name', 'vat_number'])->keyBy('id');
        $enrich = fn (array $dispute) => $dispute + [
            'legal_name' => $taxpayers[$dispute['taxpayer_id']]->legal_name ?? null,
            'vat_number' => $taxpayers[$dispute['taxpayer_id']]->vat_number ?? null,
        ];

        $disputes = collect($result['disputes'])->map($enrich)->all();

        // No dispute ever carries a status other than FILED and decided_at
        // is never set (see this class's own doc comment on the missing
        // decide path), so a status/decision-outcome stat tile would always
        // read the same as "Total" -- these four are chosen to be
        // genuinely informative instead: volume, recent filing activity,
        // and how many distinct taxpayers are disputing.
        $monthStart = now()->startOfMonth()->toISOString();
        $weekStart = now()->startOfWeek()->toISOString();
        $metrics = [
            'total' => count($all),
            'this_month' => collect($all)->filter(fn (array $d) => ($d['filed_at'] ?? '') >= $monthStart)->count(),
            'this_week' => collect($all)->filter(fn (array $d) => ($d['filed_at'] ?? '') >= $weekStart)->count(),
            'taxpayers' => collect($all)->pluck('taxpayer_id')->filter()->unique()->count(),
        ];
        $byType = collect($all)->groupBy('disputed_resource_type')
            ->map(fn ($group, $type) => ['type' => $type, 'count' => $group->count()])
            ->sortByDesc('count')->values()->all();
        $topTaxpayers = collect($all)->filter(fn (array $d) => $d['taxpayer_id'])->groupBy('taxpayer_id')
            ->map(fn ($group, $taxpayerId) => [
                'taxpayer_id' => $taxpayerId,
                'legal_name' => $taxpayers[$taxpayerId]->legal_name ?? null,
                'vat_number' => $taxpayers[$taxpayerId]->vat_number ?? null,
                'dispute_count' => $group->count(),
            ])->sortByDesc('dispute_count')->take(10)->values()->all();

        return view('disputes.index', [
            'disputes' => $disputes, 'status' => $request->query('status', ''),
            'canFile' => $actor->hasAppPermission('disputes:manage'),
            'isNational' => TaxpayerScope::isNational($actor),
            'metrics' => $metrics, 'byType' => $byType, 'topTaxpayers' => $topTaxpayers,
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $dispute = Dispute::when(! TaxpayerScope::isNational($actor), fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))->find($id);
        // 404, not 403 -- matching Invoices'/VAT-periods' own no-resource-
        // existence-disclosure precedent (DisputeService has no dedicated
        // single-read method with its own tenant-scope exception to defer
        // to here, unlike Audit Cases' timeline()/evidence()/notes()).
        abort_if(! $dispute, Response::HTTP_NOT_FOUND);
        $taxpayer = Taxpayer::find($dispute->taxpayer_id);

        return view('disputes.show', ['dispute' => $dispute, 'taxpayer' => $taxpayer]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'disputes:manage');
        $actor = $request->user();

        $taxpayerId = null;
        $disputingOrganisation = $actor->organisation();
        if (TaxpayerScope::isNational($actor)) {
            $vatNumber = (string) $request->input('vat_number');
            $taxpayer = Taxpayer::where('vat_number', $vatNumber)->first();
            if (! $taxpayer) {
                return back()->withErrors(['vat_number' => 'No taxpayer is registered with that VAT number.'])->withInput();
            }
            $taxpayerId = $taxpayer->id;
            $disputingOrganisation = $taxpayer->organisation;
        }

        $payload = [
            'schema_version' => '1.0.0', 'taxpayer_id' => $taxpayerId, 'audit_case_id' => $request->input('audit_case_id') ?: null,
            'disputed_resource_type' => (string) $request->input('disputed_resource_type'), 'disputed_resource_id' => (string) $request->input('disputed_resource_id'),
            'grounds' => (string) $request->input('grounds'), 'disputed_amount_cents' => $this->safeDecimalCentsInput($request->input('disputed_amount')),
            // Multi-tenant SaaS pivot phase 4 (2026-09-24): defaults to the
            // disputing party's own organisation currency (the actor's
            // own when taxpayer-scoped, the resolved target taxpayer's
            // when national), not always 'NAD'.
            'currency' => (string) ($request->input('currency') ?: ($disputingOrganisation?->currencyCode() ?? 'NAD')),
        ];

        try {
            $dispute = $this->disputes->file($payload, $actor, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('disputes.show', $dispute['id'])->with('status', 'Dispute filed.');
    }

    /** @return array<string, string> */
    private function fieldErrors(ComplianceValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn (array $error) => [ltrim($error['path'], '/') ?: 'form' => $error['message']])->all();
    }
}
