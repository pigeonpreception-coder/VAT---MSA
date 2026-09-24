<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\BusinessParty;
use App\Models\ExpenseCategory;
use App\Models\PurchaseOrder;
use App\Services\Business\PurchaseOrderService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Serves accounting.purchase-orders (/accounting/purchase-orders), a route
 * that was a $plannedRoute stub in routes/web.php until now (removed;
 * route name kept identical so the sidebar's Accounting & Finance >
 * Purchase Orders link needed no change). Unlike the Supplier/Customer
 * Ledger and Budgets pages, the index view itself is gated `accounting:read`
 * (matching the placeholder) but every write action is gated
 * `accounting:post` -- the same permission `AccountingController`'s own
 * journal-posting API already uses -- except convert(), gated
 * `expenses:manage` directly, since that is the real permission boundary
 * `ExpenseService::create()` itself already enforces for the Expense this
 * action creates.
 */
class PurchaseOrderViewController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders, private readonly OrganisationResolver $organisations) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'accounting:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));

        $orderModels = PurchaseOrder::where('organisation_id', $organisation->id)->with(['supplier', 'category'])
            ->orderByDesc('issue_date')->orderByDesc('created_at')->limit(200)->get();
        $orders = $orderModels->map(fn (PurchaseOrder $order) => [
            'id' => $order->id, 'po_number' => $order->po_number, 'issue_date' => $order->issue_date->toDateString(),
            'valid_until' => $order->valid_until->toDateString(), 'description' => $order->description,
            'supplier_name' => optional($order->supplier)->display_name, 'category_name' => optional($order->category)->name,
            'currency' => $order->currency, 'total_cents' => (int) $order->total_cents, 'status' => $order->status,
            'created_by' => $order->created_by, 'rejection_reason' => $order->rejection_reason, 'cancellation_reason' => $order->cancellation_reason,
            'converted_expense_id' => $order->converted_expense_id,
        ]);

        $suppliers = BusinessParty::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')
            ->whereHas('relationships', fn ($q) => $q->where('relationship', 'SUPPLIER')->where('status', 'ACTIVE'))
            ->orderBy('display_name')->get();
        $categories = ExpenseCategory::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->orderBy('name')->get();

        return view('accounting.purchase-orders', [
            'orders' => $orders, 'suppliers' => $suppliers, 'categories' => $categories,
            'canManage' => $request->user()->can('permission', 'accounting:post'), 'canConvert' => $request->user()->can('permission', 'expenses:manage'),
            'actorId' => $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'accounting:post');
        $netCents = $this->safeIntegerInput($request->input('net_cents'));
        $taxCents = $this->safeIntegerInput($request->input('tax_cents'));
        $payload = [
            'schema_version' => '1.0.0', 'supplier_party_id' => $request->input('supplier_party_id'),
            'category_id' => $request->input('category_id'), 'po_number' => $request->input('po_number'),
            'currency' => $request->user()->organisation()?->currencyCode() ?? 'NAD', 'issue_date' => $request->input('issue_date'), 'valid_until' => $request->input('valid_until'),
            'description' => $request->input('description'), 'net_cents' => $netCents, 'tax_cents' => $taxCents,
            'total_cents' => $netCents + $taxCents, 'notes' => $request->input('notes') ?: null,
        ];

        try {
            $this->orders->create($payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('accounting.purchase-orders')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('accounting.purchase-orders')->withErrors(['purchase_order' => $e->getMessage()])->withInput();
        }

        return redirect()->route('accounting.purchase-orders')->with('status', 'Purchase order created.');
    }

    public function submit(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'accounting:post');

        return $this->runTransition(fn () => $this->orders->submit($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Purchase order submitted for approval.');
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'accounting:post');

        return $this->runTransition(fn () => $this->orders->approve($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Purchase order approved.');
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'accounting:post');
        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        return $this->runTransition(fn () => $this->orders->reject($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Purchase order rejected.');
    }

    public function issue(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'accounting:post');

        return $this->runTransition(fn () => $this->orders->issue($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Purchase order issued to the supplier.');
    }

    public function convert(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $payload = ['expense_number' => $request->input('expense_number')];

        return $this->runTransition(fn () => $this->orders->convertToExpense($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Purchase order converted to a supplier expense.');
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'accounting:post');
        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        return $this->runTransition(fn () => $this->orders->cancel($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Purchase order cancelled.');
    }

    private function runTransition(\Closure $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (BusinessValidationException $e) {
            return redirect()->route('accounting.purchase-orders')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('accounting.purchase-orders')->withErrors(['purchase_order' => $e->getMessage()]);
        }

        return redirect()->route('accounting.purchase-orders')->with('status', $successMessage);
    }
}
