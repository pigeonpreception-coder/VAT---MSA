<?php

namespace App\Http\Controllers\Refund;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\ComplianceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\RefundClaim;
use App\Services\Refund\RefundService;
use App\Support\Access\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Blade UI for RefundService (refund claims: request, eligibility
 * checks, maker-checker transition, taxpayer dispute), alongside the JSON
 * API surface RefundController already exposes -- see
 * InvoiceViewController's own doc comment for why this app keeps a
 * dedicated Blade-rendering controller next to each JSON one.
 *
 * Unlike every other module ported so far, the JSON API here genuinely
 * has no list/detail (index/show) endpoint at all -- confirmed by reading
 * RefundController directly: only store/checks/transition/dispute exist.
 * `index()`/`show()` below therefore query `RefundClaim` directly rather
 * than reusing an existing snapshot method, the same way
 * VatLifecycleViewController's `periodAdjustments()` queried
 * `VatAdjustment` directly where the JSON API's own snapshot didn't cover
 * per-period detail. Every write action (`storeRequest`/`storeTransition`/
 * `storeDispute`) still reuses `RefundService` directly, the same service
 * instance the JSON controller calls.
 */
class RefundViewController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'refunds:read');
        $actor = $request->user();
        $scoped = ! TenantScope::isNational($actor);

        $claims = RefundClaim::with('taxpayer')
            ->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
            ->orderByDesc('requested_at')->get()
            ->map(fn (RefundClaim $claim) => $this->presentSummary($claim))->all();

        return view('refunds.index', ['claims' => $claims]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'refunds:read');
        $actor = $request->user();
        $scoped = ! TenantScope::isNational($actor);

        $claim = RefundClaim::with(['taxpayer', 'checks', 'transitions.actor'])
            ->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
            ->find($id);
        // 404, not 403, for an out-of-scope claim -- matching Invoices' own
        // no-resource-existence-disclosure precedent (unlike VAT return
        // versions, which the underlying service itself throws a 403 for;
        // this page builds its own scoped query instead, so it can choose
        // the more privacy-preserving option here).
        abort_if(! $claim, Response::HTTP_NOT_FOUND);

        // DISPUTE is a distinct, taxpayer-only endpoint (storeDispute), not
        // part of the officer-only transition dropdown -- it does appear
        // in REFUND_CLAIM_TRANSITIONS for REJECTED (alongside CLOSE), since
        // that's the single state table both RefundService::transition()
        // (officer-only) and ::dispute() (taxpayer-only) validate against;
        // stripped out here so it isn't offered twice under two different
        // forms.
        $validActions = array_values(array_diff(ComplianceValidator::refundClaimActionsFor($claim->status), ['DISPUTE']));

        return view('refunds.show', [
            'claim' => $this->presentDetail($claim),
            'validActions' => $validActions,
            'canReview' => $request->user()->hasAppPermission('refunds:review'),
            'canDispute' => $claim->status === 'REJECTED' && $claim->requested_by === $actor->id,
        ]);
    }

    public function storeRequest(Request $request, string $versionId): RedirectResponse
    {
        $this->authorize('permission', 'refunds:request');

        $payload = ['schema_version' => '1.0.0', 'vat_return_version_id' => $versionId];

        try {
            $claim = $this->refunds->request($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceResourceException|RepositoryConflictException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('refunds.show', $claim['id'])->with('status', 'Refund claim submitted.');
    }

    public function storeTransition(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'refunds:review');

        $payload = [
            'schema_version' => '1.0.0',
            'action' => (string) $request->input('action'),
            'findings' => (string) $request->input('findings'),
        ];

        try {
            $this->refunds->transition($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e));
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('refunds.show', $id)->with('status', 'Decision recorded.');
    }

    public function storeDispute(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'refunds:request');

        $payload = ['schema_version' => '1.0.0', 'action' => 'DISPUTE', 'findings' => (string) $request->input('findings')];

        try {
            $this->refunds->dispute($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e));
        } catch (ComplianceResourceException|RepositoryConflictException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('refunds.show', $id)->with('status', 'Dispute submitted.');
    }

    /** @return array<string, string> */
    private function fieldErrors(ComplianceValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn (array $error) => [ltrim($error['path'], '/') ?: 'form' => $error['message']])->all();
    }

    /** @return array<string, mixed> */
    private function presentSummary(RefundClaim $claim): array
    {
        return [
            'id' => $claim->id, 'claim_number' => $claim->claim_number, 'legal_name' => $claim->taxpayer?->legal_name,
            'vat_number' => $claim->taxpayer?->vat_number, 'amount_cents' => (int) $claim->amount_cents, 'currency' => $claim->currency,
            'status' => $claim->status, 'risk_tier' => $claim->risk_tier, 'requested_at' => optional($claim->requested_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentDetail(RefundClaim $claim): array
    {
        return [
            'id' => $claim->id, 'claim_number' => $claim->claim_number, 'legal_name' => $claim->taxpayer?->legal_name,
            'vat_number' => $claim->taxpayer?->vat_number, 'vat_return_version_id' => $claim->vat_return_version_id,
            'amount_cents' => (int) $claim->amount_cents, 'currency' => $claim->currency, 'status' => $claim->status,
            'evidence_status' => $claim->evidence_status, 'risk_tier' => $claim->risk_tier, 'requested_at' => optional($claim->requested_at)->toISOString(),
            'approved_at' => optional($claim->approved_at)->toISOString(), 'offset_amount_cents' => (int) $claim->offset_amount_cents,
            'net_payable_cents' => $claim->net_payable_cents === null ? null : (int) $claim->net_payable_cents,
            'dispute_reason' => $claim->dispute_reason, 'requested_by' => $claim->requested_by,
            'checks' => $claim->checks->map(fn ($c) => ['check_code' => $c->check_code, 'status' => $c->status, 'rationale' => $c->rationale])->all(),
            'transitions' => $claim->transitions->map(fn ($t) => [
                'action' => $t->action, 'from_status' => $t->from_status, 'to_status' => $t->to_status,
                'actor_name' => $t->actor?->name, 'findings' => $t->findings, 'occurred_at' => optional($t->occurred_at)->toISOString(),
            ])->all(),
        ];
    }
}
