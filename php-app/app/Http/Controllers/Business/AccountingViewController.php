<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/accounting/page.tsx -- the controlled
 * general ledger dashboard. Unlike the business parties and quotations
 * slices, the source's own page here is read-only: it has no create/post
 * forms of any kind, and its own closing note says so explicitly
 * ("Interactive journal authoring and approval queues will be expanded
 * with the VAT close workflow") -- ported verbatim below, not simplified
 * away. App\Services\Business\AccountingService already fully supports
 * posting journals, creating accounts, reversing entries, closing periods,
 * trial balance and financial statements (reachable today at
 * /api/v1/accounting/**, see App\Http\Controllers\Business\
 * AccountingController), so nothing here is a backend gap -- only a UI one,
 * and the source's own scope for this specific screen is read-only, so
 * this view stays read-only too rather than inventing forms the source
 * doesn't have.
 *
 * Queries App\Models\ChartOfAccount and App\Models\JournalEntry directly,
 * mirroring AccountingController::indexAccounts/indexJournals's own
 * precedent (a simple real query, not the source's fixed
 * getBusinessPlatformSnapshot list -- see docs/MIGRATION_MATRIX.md) rather
 * than adding a third copy of the same two queries behind a new service
 * method.
 */
class AccountingViewController extends Controller
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'accounting:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));

        $accounts = ChartOfAccount::where('organisation_id', $organisation->id)->orderBy('code')->limit(200)->get();
        $journals = JournalEntry::where('organisation_id', $organisation->id)->orderByDesc('journal_date')->orderByDesc('created_at')->limit(100)->get();

        return view('accounting.index', [
            'accounts' => $accounts,
            'journals' => $journals,
            'postedCount' => $journals->where('status', 'POSTED')->count(),
        ]);
    }
}
