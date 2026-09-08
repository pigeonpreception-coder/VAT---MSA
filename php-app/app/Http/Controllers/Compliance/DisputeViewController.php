<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceResourceException;
use App\Exceptions\ComplianceValidationException;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Taxpayer;
use App\Services\Compliance\DisputeService;
use App\Support\Access\TenantScope;
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

        $result = $this->disputes->search($actor, $request->only('status'));
        $taxpayerIds = collect($result['disputes'])->pluck('taxpayer_id')->filter()->unique();
        $taxpayers = Taxpayer::whereIn('id', $taxpayerIds)->get(['id', 'legal_name', 'vat_number'])->keyBy('id');

        $disputes = collect($result['disputes'])->map(fn (array $dispute) => $dispute + [
            'legal_name' => $taxpayers[$dispute['taxpayer_id']]->legal_name ?? null,
            'vat_number' => $taxpayers[$dispute['taxpayer_id']]->vat_number ?? null,
        ])->all();

        return view('disputes.index', [
            'disputes' => $disputes, 'status' => $request->query('status', ''),
            'canFile' => $actor->hasAppPermission('disputes:manage'),
            'isNational' => TenantScope::isNational($actor),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $dispute = Dispute::when(! TenantScope::isNational($actor), fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))->find($id);
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
        if (TenantScope::isNational($actor)) {
            $vatNumber = (string) $request->input('vat_number');
            $taxpayer = Taxpayer::where('vat_number', $vatNumber)->first();
            if (! $taxpayer) {
                return back()->withErrors(['vat_number' => 'No taxpayer is registered with that VAT number.'])->withInput();
            }
            $taxpayerId = $taxpayer->id;
        }

        $payload = [
            'schema_version' => '1.0.0', 'taxpayer_id' => $taxpayerId, 'audit_case_id' => $request->input('audit_case_id') ?: null,
            'disputed_resource_type' => (string) $request->input('disputed_resource_type'), 'disputed_resource_id' => (string) $request->input('disputed_resource_id'),
            'grounds' => (string) $request->input('grounds'), 'disputed_amount_cents' => $this->centsFromDecimal($request->input('disputed_amount')),
            'currency' => (string) ($request->input('currency') ?: 'NAD'),
        ];

        try {
            $dispute = $this->disputes->file($payload, $actor, (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('disputes.show', $dispute['id'])->with('status', 'Dispute filed.');
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
