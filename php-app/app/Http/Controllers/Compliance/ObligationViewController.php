<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceResourceException;
use App\Exceptions\ComplianceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\Taxpayer;
use App\Services\Compliance\ObligationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Real Blade UI for ObligationService (Module 3 Phase D: NamRA-imposed tax
 * obligations and marking them satisfied), alongside the JSON API surface
 * ObligationController already exposes -- see InvoiceViewController's own
 * doc comment for why this app keeps a dedicated Blade-rendering controller
 * next to each JSON one.
 *
 * Unlike Disputes (taxpayer-initiated) but like Risk Indicators (officer-
 * only), obligations are entirely NamRA-imposed: ObligationService::create()
 * and ::markSatisfied() both independently throw AuthorizationException
 * unless the actor is national-scope, regardless of what the controller
 * checks -- `obligations:manage` is held only by PILOT_ADMIN and the
 * NAMRA_* national roles in Permissions::ROLE_PERMISSIONS, never by a
 * taxpayer role. A taxpayer can still read their own obligations via
 * `compliance:read` (ObligationService::search() scopes by tenant like
 * every other search() in this module), so the list itself stays visible to
 * both, only the create/satisfy forms are officer-gated in the view.
 *
 * Deliberately a single-page module with no separate detail route: unlike
 * Risk Indicators or Audit Cases, an obligation carries no timeline,
 * evidence, or notes of its own -- present() already returns everything
 * there is to show, and the only two actions (create, mark satisfied) both
 * make sense inline on the list row rather than behind a second page. This
 * mirrors the same "don't build UI surface the backend doesn't need"
 * discipline used everywhere else in this build-out.
 */
class ObligationViewController extends Controller
{
    public function __construct(private readonly ObligationService $obligations) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $result = $this->obligations->search($actor, $request->only('status'));
        $taxpayerIds = collect($result['obligations'])->pluck('taxpayer_id')->filter()->unique();
        $taxpayers = Taxpayer::whereIn('id', $taxpayerIds)->get(['id', 'legal_name', 'vat_number'])->keyBy('id');

        $obligations = collect($result['obligations'])->map(fn (array $obligation) => $obligation + [
            'legal_name' => $taxpayers[$obligation['taxpayer_id']]->legal_name ?? null,
            'vat_number' => $taxpayers[$obligation['taxpayer_id']]->vat_number ?? null,
        ])->all();

        return view('obligations.index', [
            'obligations' => $obligations, 'status' => $request->query('status', ''),
            'canManage' => $actor->hasAppPermission('obligations:manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'obligations:manage');

        $vatNumber = (string) $request->input('vat_number');
        $taxpayer = Taxpayer::where('vat_number', $vatNumber)->first();
        if (! $taxpayer) {
            return back()->withErrors(['vat_number' => 'No taxpayer is registered with that VAT number.'])->withInput();
        }

        $payload = [
            'schema_version' => '1.0.0', 'taxpayer_id' => $taxpayer->id,
            'obligation_type' => mb_strtoupper((string) $request->input('obligation_type')),
            'period_code' => (string) $request->input('period_code'), 'due_date' => (string) $request->input('due_date'),
            'amount_cents' => $this->centsFromDecimal($request->input('amount')), 'currency' => (string) ($request->input('currency') ?: 'NAD'),
        ];

        try {
            $this->obligations->create($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('obligations.index')->with('status', "Obligation created for {$taxpayer->legal_name}.");
    }

    public function storeSatisfaction(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'obligations:manage');

        $payload = ['schema_version' => '1.0.0', 'notes' => (string) $request->input('notes')];

        try {
            $this->obligations->markSatisfied($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('obligations.index')->with('status', 'Obligation marked satisfied.');
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
