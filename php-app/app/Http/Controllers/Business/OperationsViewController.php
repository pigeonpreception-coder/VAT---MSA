<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\DocumentMetadata;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ImportRecord;
use App\Models\InventoryBalance;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectCost;
use App\Services\Business\BusinessPartyService;
use App\Services\Business\ExpenseService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from the source's own app/operations/page.tsx +
 * ExpenseDecisionActions.tsx + ExpenseReceiptActions.tsx -- "Business
 * operations": the expense register (with receipt evidence and independent
 * maker-checker decisions), inventory balances and project control. Reuses
 * App\Services\Business\ExpenseService for expense writes, plus direct
 * InventoryBalance/Project(+Budget/Cost) reads mirroring
 * App\Http\Controllers\Business\InventoryController::indexMovements and
 * App\Http\Controllers\Business\ProjectController::index's own existing
 * inline-query precedent (neither has a dedicated "list with enrichment"
 * service method, so this doesn't add a second, competing one) -- no
 * second query/command path anywhere in this controller.
 *
 * The source's fourth panel, "Import VAT evidence" (customs
 * declarations), is rendered read-only here via App\Models\ImportRecord --
 * a plain direct read (`ImportRecord::where('organisation_id', ...)`, the
 * same inline-query precedent as InventoryBalance/Project above). This
 * page itself still only reads that table; the one write path it now has
 * is `App\Services\Business\ForeignInvoiceService`'s E-Tariff pull,
 * reached from the Foreign Invoices screen (user's own later explicit
 * request), not from here -- see that service's own doc comment for why
 * this table's original read-only-by-source-fidelity boundary was
 * deliberately reversed rather than silently contradicted.
 *
 * `linkReceipt()` closes the gap the doc comment above used to name here:
 * `ExpenseService::linkReceipt()` (LinkExpenseReceipt) is now built, so
 * this row-level action lets an already-uploaded, already-scanned-clean
 * document be linked to a DRAFT expense by id -- the id is entered
 * directly rather than picked from a list (this page has no query for
 * "clean, unlinked documents for this expense" of its own, and adding one
 * isn't this command's job), matching source's own two-step shape:
 * the row's existing "Upload receipt" link already sends the actor to
 * `documents.index` (owner_domain/owner_resource_id prefilled) to upload
 * and await a clean scan first, then they return here to link it, the
 * same two-step split `ExpenseReceiptActions.tsx`'s own sibling "Upload"/
 * "Link" actions use.
 *
 * One deliberate, documented deviation from source, closing a confirmed
 * dead end the same way the quotations slice's "Send" action did: the
 * source's own operations page has no create-expense form and no
 * DRAFT -> SUBMITTED action anywhere (confirmed by a full-repo grep of
 * `app/**\/*.tsx` for "submission"/"submitExpense" -- neither appears
 * outside unrelated VAT-return/registration/invoice files), even though
 * `ExpenseService::create`/`submit` are fully built. Without either, no
 * expense created through this application could ever reach the
 * maker-checker decision this same page's own UI is built around. This
 * controller adds both.
 */
class OperationsViewController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly BusinessPartyService $parties,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'expenses:read');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        // Red-team punch list #12 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
        // 2026-09-15.md): this used to lazy-load `category`/`supplier`
        // and run a `DocumentMetadata::find()` per expense row -- up to 3
        // extra queries per row, invisible against the dev seed's near-
        // empty tables but a genuine N+1 at real register volume. A
        // synthetic load seed (2026-09-15) made this concretely visible
        // for the first time. `->with()` plus one batched `whereIn()`
        // lookup for receipts collapses it to a fixed, small number of
        // queries regardless of row count.
        $expenseModels = Expense::where('organisation_id', $organisation->id)->with(['category', 'supplier'])
            ->orderByDesc('expense_date')->orderByDesc('created_at')->limit(100)->get();
        $categories = ExpenseCategory::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->orderBy('name')->get();
        $receiptIds = $expenseModels->pluck('receipt_document_id')->filter()->values();
        $receiptsById = $receiptIds->isEmpty() ? collect() : DocumentMetadata::whereIn('id', $receiptIds)->get()->keyBy('id');
        $expenses = $expenseModels->map(function (Expense $expense) use ($receiptsById) {
            $receipt = $expense->receipt_document_id ? $receiptsById->get($expense->receipt_document_id) : null;

            return [
                'id' => $expense->id, 'expense_number' => $expense->expense_number, 'expense_date' => $expense->expense_date->toDateString(),
                'category_name' => optional($expense->category)->name, 'supplier_name' => optional($expense->supplier)->display_name,
                'description' => $expense->description, 'currency' => $expense->currency, 'total_cents' => (int) $expense->total_cents,
                'status' => $expense->status, 'created_by' => $expense->created_by,
                'requires_receipt' => (bool) optional($expense->category)->requires_receipt,
                'receipt' => $receipt ? ['file_name' => $receipt->file_name, 'scan_status' => $receipt->scan_status, 'status' => $receipt->status] : null,
            ];
        });

        $balances = InventoryBalance::where('organisation_id', $organisation->id)->with(['warehouse', 'product'])->orderByDesc('updated_at')->limit(200)->get();

        // Same fix shape as the expense register above: budget/cost sums
        // were one extra query each per project row (2 extra per row at
        // 100 projects). Batched via whereIn()+groupBy() into 2 queries
        // total, keyed by project_id for the map below.
        $projectModels = Project::where('organisation_id', $organisation->id)->with('customer')->orderByDesc('start_date')->limit(100)->get();
        $projectIds = $projectModels->pluck('id');
        $budgetsByProject = $projectIds->isEmpty() ? collect() : ProjectBudget::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(approved_amount_cents) as total')->groupBy('project_id')->get()->keyBy('project_id');
        $costsByProject = $projectIds->isEmpty() ? collect() : ProjectCost::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(amount_cents) as total')->groupBy('project_id')->get()->keyBy('project_id');
        $projects = $projectModels->map(fn (Project $project) => [
            'id' => $project->id, 'code' => $project->code, 'name' => $project->name,
            'customer_name' => optional($project->customer)->display_name, 'currency' => $project->currency,
            'start_date' => $project->start_date->toDateString(), 'end_date' => optional($project->end_date)->toDateString(),
            'status' => $project->status,
            'budget_cents' => (int) optional($budgetsByProject->get($project->id))->total,
            'cost_cents' => (int) optional($costsByProject->get($project->id))->total,
        ]);

        $partiesSnapshot = $this->parties->search($user, $organisation->id, []);
        $suppliers = collect($partiesSnapshot['parties'])->filter(fn ($p) => $p['status'] === 'ACTIVE' && in_array('SUPPLIER', $p['relationships'], true))->values();

        $importRecords = ImportRecord::where('organisation_id', $organisation->id)->orderByDesc('declaration_date')->limit(100)->get();

        return view('operations.index', [
            'expenses' => $expenses,
            'expenseValueCents' => $expenses->sum('total_cents'),
            'categories' => $categories,
            'suppliers' => $suppliers,
            'balances' => $balances,
            'projects' => $projects,
            'importRecords' => $importRecords,
            'canDecideExpenses' => $user->hasAppPermission('expenses:manage'),
            'canManageExpenses' => $user->hasAppPermission('expenses:manage'),
            'actorId' => $user->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $netCents = $this->safeIntegerInput($request->input('net_cents'));
        $taxCents = $this->safeIntegerInput($request->input('tax_cents'));
        $payload = [
            'schema_version' => '1.0.0', 'category_id' => $request->input('category_id'),
            'supplier_party_id' => $request->input('supplier_party_id') ?: null,
            'expense_number' => $request->input('expense_number'), 'expense_date' => $request->input('expense_date'),
            'description' => $request->input('description'), 'currency' => 'NAD',
            'net_cents' => $netCents, 'tax_cents' => $taxCents, 'total_cents' => $netCents + $taxCents,
        ];

        try {
            $this->expenses->create($payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null);        } catch (BusinessValidationException $e) {
            return redirect()->route('operations.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('operations.index')->withErrors(['expense' => $e->getMessage()])->withInput();
        }

        return redirect()->route('operations.index')->with('status', 'Expense recorded.');
    }

    public function submit(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');

        return $this->runTransition(fn () => $this->expenses->submit($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Expense submitted for independent review.');    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');

        return $this->runTransition(fn () => $this->expenses->approve($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Expense approved.');    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        return $this->runTransition(fn () => $this->expenses->reject($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Expense rejected.');    }

    public function linkReceipt(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $payload = ['schema_version' => '1.0.0', 'receipt_document_id' => (string) $request->input('receipt_document_id')];

        return $this->runTransition(fn () => $this->expenses->linkReceipt($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null), 'Receipt linked.');
    }

    private function runTransition(\Closure $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (BusinessValidationException $e) {
            return redirect()->route('operations.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('operations.index')->withErrors(['expense' => $e->getMessage()]);
        }

        return redirect()->route('operations.index')->with('status', $successMessage);
    }
}
