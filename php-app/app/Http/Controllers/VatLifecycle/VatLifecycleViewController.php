<?php

namespace App\Http\Controllers\VatLifecycle;

use App\Exceptions\RepositoryConflictException;
use App\Exceptions\VatLifecycleResourceException;
use App\Exceptions\VatLifecycleValidationException;
use App\Http\Controllers\Controller;
use App\Models\ApprovalTask;
use App\Models\VatAdjustment;
use App\Services\VatLifecycle\VatLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Blade UI for VatLifecycleService (VAT periods, return generation,
 * maker-checker approval, ITAS submission), alongside the JSON API surface
 * VatLifecycleController already exposes -- see InvoiceViewController's own
 * doc comment for why this app keeps a dedicated Blade-rendering controller
 * next to each JSON one rather than serving Blade from the same routes.
 *
 * Unlike InvoiceViewController (read-only so far), this controller also
 * carries the module's real write actions -- generate/adjust/approve/
 * submit -- as plain POST -> redirect forms, each reusing
 * VatLifecycleService directly (the exact same service instance the JSON
 * controller calls, so behaviour, validation and audit trail are identical
 * regardless of which surface triggered the command). Each Blade form
 * submission gets its own freshly-generated idempotency key
 * (VatLifecycleService's commands are idempotency-key-gated, matching every
 * other command in this migration): a real browser form POST is inherently
 * a *new* user-initiated attempt each time, not a client retrying a prior
 * request with the same key the way the JSON API's own callers might.
 *
 * Domain exceptions that already have their own clean render() (see each
 * exception class) are deliberately NOT let through to Laravel's global
 * handler here for these write actions -- that would show a raw JSON body
 * on what is otherwise a normal web form. Each action catches them and
 * redirects back with a validation-style error instead, exactly how a
 * Laravel form is supposed to fail.
 */
class VatLifecycleViewController extends Controller
{
    public function __construct(private readonly VatLifecycleService $vatLifecycle) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'returns:read');

        return view('vat-periods.index', ['snapshot' => $this->vatLifecycle->snapshot($request->user())]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'returns:read');
        $snapshot = $this->vatLifecycle->snapshot($request->user());
        $period = collect($snapshot['periods'])->firstWhere('id', $id);
        abort_if(! $period, Response::HTTP_NOT_FOUND);

        $adjustments = $this->periodAdjustments($id);
        $pendingAdjustmentTaskIds = collect($adjustments['periodAdjustments'])->where('status', 'PENDING_APPROVAL')->pluck('id');
        $pendingAdjustmentApprovals = ApprovalTask::where('resource_type', 'VAT_ADJUSTMENT')
            ->whereIn('resource_id', $pendingAdjustmentTaskIds)->where('status', 'PENDING')->get()
            ->keyBy('resource_id');

        return view('vat-periods.show', [
            'period' => $period,
            'canManageAdjustments' => $request->user()->hasAppPermission('vat-adjustments:manage'),
            'canGenerateReturn' => $request->user()->hasAppPermission('returns:generate'),
            'canApprove' => $request->user()->hasAppPermission('returns:approve'),
            'currentUserId' => $request->user()->id,
            'pendingAdjustmentApprovals' => $pendingAdjustmentApprovals,
        ] + $adjustments);
    }

    public function showReturn(Request $request, string $id): View
    {
        $this->authorize('permission', 'returns:read');

        try {
            $detail = $this->vatLifecycle->returnDetail($id, $request->user());
        } catch (VatLifecycleResourceException $e) {
            abort(404, $e->getMessage());
        }

        return view('vat-returns.show', [
            'detail' => $detail,
            'user' => $request->user(),
        ]);
    }

    public function storeAdjustment(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'vat-adjustments:manage');

        $payload = [
            'schema_version' => '1.0.0',
            'adjustment_type' => (string) $request->input('adjustment_type'),
            'direction' => (string) $request->input('direction'),
            'amount_cents' => $this->centsFromDecimal($request->input('amount')),
            'reason_code' => (string) $request->input('reason_code'),
            'explanation' => (string) $request->input('explanation'),
        ];

        try {
            $this->vatLifecycle->createAdjustment($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (VatLifecycleValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (RepositoryConflictException|VatLifecycleResourceException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('vat-periods.show', $id)->with('status', 'Adjustment submitted for approval.');
    }

    public function storeReturn(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'returns:generate');

        try {
            $version = $this->vatLifecycle->generateReturn($id, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (RepositoryConflictException|VatLifecycleResourceException $e) {
            return redirect()->route('vat-periods.show', $id)->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('vat-returns.show', $version['id'])->with('status', 'A new draft return was generated.');
    }

    public function requestApproval(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'returns:generate');

        try {
            $this->vatLifecycle->requestReturnApproval($id, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (RepositoryConflictException|VatLifecycleResourceException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('vat-returns.show', $id)->with('status', 'Approval requested.');
    }

    public function submit(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'returns:submit');

        try {
            $this->vatLifecycle->submitReturn($id, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (RepositoryConflictException|VatLifecycleResourceException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('vat-returns.show', $id)->with('status', 'Submission to ITAS was recorded -- see the submission history below for its outcome.');
    }

    public function decideApproval(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'returns:approve');

        $task = ApprovalTask::find($id);
        $decisionInput = ['decision' => $request->input('decision'), 'comment' => $request->input('comment')];

        try {
            $this->vatLifecycle->decideApproval($id, $decisionInput, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (VatLifecycleValidationException $e) {
            return back()->withErrors($this->fieldErrors($e));
        } catch (RepositoryConflictException|VatLifecycleResourceException|AuthorizationException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        $adjustmentPeriodId = $task && $task->resource_type === 'VAT_ADJUSTMENT' ? VatAdjustment::find($task->resource_id)?->vat_period_id : null;
        $redirectTarget = match (true) {
            $task && $task->resource_type === 'VAT_RETURN_VERSION' => redirect()->route('vat-returns.show', $task->resource_id),
            $adjustmentPeriodId !== null => redirect()->route('vat-periods.show', $adjustmentPeriodId),
            default => redirect()->route('vat-periods.index'),
        };

        return $redirectTarget->with('status', 'Decision recorded.');
    }

    private function centsFromDecimal(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** @return array<string, string> */
    private function fieldErrors(VatLifecycleValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn (array $error) => [ltrim($error['path'], '/') ?: 'form' => $error['message']])->all();
    }

    /** @return array{periodAdjustments: list<array<string, mixed>>} */
    private function periodAdjustments(string $periodId): array
    {
        return ['periodAdjustments' => VatAdjustment::where('vat_period_id', $periodId)->orderByDesc('created_at')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'adjustment_type' => $a->adjustment_type, 'direction' => $a->direction,
                'amount_cents' => (int) $a->amount_cents, 'reason_code' => $a->reason_code, 'explanation' => $a->explanation,
                'status' => $a->status, 'created_at' => optional($a->created_at)->toISOString(),
            ])->all()];
    }
}
