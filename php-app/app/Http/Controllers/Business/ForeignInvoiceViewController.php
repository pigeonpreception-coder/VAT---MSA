<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Integrations\Etariff\EtariffPort;
use App\Models\ImportRecord;
use App\Services\Business\ForeignInvoiceService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * User's own explicit request: Foreign Invoices must be linked to NamRA's
 * E-Tariff border system so a foreign invoice's own customs value/import
 * VAT is autonomously pulled and cross-authenticated against the
 * independent duty-paid record captured at the border, not just the
 * taxpayer's own submission. Replaces the former `invoice-management.
 * foreign` planned-module placeholder, which had explicitly deferred any
 * real foreign-invoice concept for lack of a counterparty-country field
 * (see that placeholder's own former scope-note in routes/web.php).
 *
 * Built on the existing `App\Models\ImportRecord` (customs-import
 * declaration) rather than a new invoice-linked schema -- see
 * `ForeignInvoiceService`'s own doc comment for why. `index()` is a plain
 * read, matching every other Blade-view controller's own precedent;
 * `pull()` is the one write action, delegated entirely to
 * `ForeignInvoiceService::pullFromEtariff()` so this controller holds no
 * integration logic of its own.
 */
class ForeignInvoiceViewController extends Controller
{
    public function __construct(
        private readonly ForeignInvoiceService $foreignInvoices,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'imports:read');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        $records = ImportRecord::where('organisation_id', $organisation->id)->orderByDesc('declaration_date')->limit(100)->get();

        return view('invoice-management.foreign', [
            'records' => $records,
            'etariffStatus' => app(EtariffPort::class)->status($organisation->id),
            'canPull' => $user->hasAppPermission('imports:manage'),
        ]);
    }

    public function pull(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'imports:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        try {
            $result = $this->foreignInvoices->pullFromEtariff($organisation, $user, $this->formIdempotencyKey($request));
        } catch (RepositoryConflictException $e) {
            return redirect()->route('invoice-management.foreign')->withErrors(['pull' => $e->getMessage()]);
        }

        if ($result['status'] === 'BLOCKED_CONFIGURATION') {
            return redirect()->route('invoice-management.foreign')->withErrors(['pull' => $result['message']]);
        }
        if ($result['status'] === 'DUPLICATE_REQUEST_SUPPRESSED') {
            return redirect()->route('invoice-management.foreign')->with('status', $result['message']);
        }

        return redirect()->route('invoice-management.foreign')->with('status', "Pulled {$result['pulled']} declaration(s) from E-Tariff.");
    }
}
