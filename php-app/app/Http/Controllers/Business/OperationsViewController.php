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
 * declarations), is now rendered read-only via App\Models\ImportRecord --
 * a plain direct read (`ImportRecord::where('organisation_id', ...)`, the
 * same inline-query precedent as InventoryBalance/Project above), never a
 * write: a full-repo grep of the TypeScript source confirms `import_records`
 * is only ever read (by this same page and by `getBusinessPlatformSnapshot`)
 * and no command anywhere creates or updates a row. Building a "record an
 * import declaration" command would be inventing backend capability the
 * source itself never implements, not porting one -- see
 * docs/MIGRATION_MATRIX.md's own note.
 *
 * One remaining confirmed, documented scope boundary against the source:
 *  - Receipt handling stays read-only: the source's own
 *    ExpenseReceiptActions.tsx calls `POST /api/v1/expenses/{id}/receipt`
 *    to link an already-uploaded, already-scanned-clean document, but that
 *    command was never ported to App\Services\Business\ExpenseService (no
 *    method, no route) -- and its own "Upload receipt" action depends on
 *    the Documents module's own upload UI, which likewise has no Blade
 *    screen yet. This view shows the linked receipt's real state
 *    (read-only, via App\Models\DocumentMetadata directly) but does not
 *    invent the missing link/upload commands.
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

        $expenseModels = Expense::where('organisation_id', $organisation->id)->orderByDesc('expense_date')->orderByDesc('created_at')->limit(100)->get();
        $categories = ExpenseCategory::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->orderBy('name')->get();
        $expenses = $expenseModels->map(function (Expense $expense) {
            $receipt = $expense->receipt_document_id ? DocumentMetadata::find($expense->receipt_document_id) : null;

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

        $projectModels = Project::where('organisation_id', $organisation->id)->with('customer')->orderByDesc('start_date')->limit(100)->get();
        $projects = $projectModels->map(fn (Project $project) => [
            'id' => $project->id, 'code' => $project->code, 'name' => $project->name,
            'customer_name' => optional($project->customer)->display_name, 'currency' => $project->currency,
            'start_date' => $project->start_date->toDateString(), 'end_date' => optional($project->end_date)->toDateString(),
            'status' => $project->status,
            'budget_cents' => (int) ProjectBudget::where('project_id', $project->id)->sum('approved_amount_cents'),
            'cost_cents' => (int) ProjectCost::where('project_id', $project->id)->sum('amount_cents'),
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
        $netCents = (int) $request->input('net_cents', 0);
        $taxCents = (int) $request->input('tax_cents', 0);
        $payload = [
            'schema_version' => '1.0.0', 'category_id' => $request->input('category_id'),
            'supplier_party_id' => $request->input('supplier_party_id') ?: null,
            'expense_number' => $request->input('expense_number'), 'expense_date' => $request->input('expense_date'),
            'description' => $request->input('description'), 'currency' => 'NAD',
            'net_cents' => $netCents, 'tax_cents' => $taxCents, 'total_cents' => $netCents + $taxCents,
        ];

        try {
            $this->expenses->create($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('operations.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('operations.index')->withErrors(['expense' => $e->getMessage()])->withInput();
        }

        return redirect()->route('operations.index')->with('status', 'Expense recorded.');
    }

    public function submit(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');

        return $this->runTransition(fn () => $this->expenses->submit($id, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null), 'Expense submitted for independent review.');
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');

        return $this->runTransition(fn () => $this->expenses->approve($id, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null), 'Expense approved.');
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        return $this->runTransition(fn () => $this->expenses->reject($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null), 'Expense rejected.');
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
