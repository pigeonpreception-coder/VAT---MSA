<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\InvoiceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Quotation;
use App\Services\Business\BusinessPartyService;
use App\Services\Business\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from the source's own app/commercial/page.tsx + QuotationForm.tsx +
 * QuotationActions.tsx + app/commercial/quotations/[id]/edit/page.tsx +
 * QuotationEditForm.tsx -- the quotation register, issue form, full lifecycle
 * actions (send/accept/reject/expire/convert) and the multi-line revision
 * editor. Reuses App\Services\Business\QuotationService and
 * App\Services\Business\BusinessPartyService directly (the same methods the
 * JSON API at /api/v1/quotations already serves) plus a direct read of
 * App\Models\Product, matching App\Http\Controllers\Business\
 * InventoryController::indexProducts's own precedent of querying the model
 * inline rather than through a dedicated service method (none exists) -- no
 * second query/command path anywhere in this controller.
 *
 * One deliberate, documented deviation from the source, not a silent fix:
 * the source's own `createQuotation` always creates a quotation in `DRAFT`
 * status, but neither app/commercial/page.tsx nor QuotationActions.tsx (nor
 * anywhere else in the source's own UI, confirmed by a full-repo grep for
 * "sending"/"sendQuotation") ever surfaces a way to reach the already-built
 * `sendQuotation` (DRAFT -> ISSUED) transition -- a quotation created
 * through the source's own screen is permanently stuck, unreachable by any
 * later lifecycle action, a genuine dead end in the original. This
 * controller adds a "Send" action for a `DRAFT` quotation (calling
 * QuotationService::send, already fully built and already reachable via
 * POST /api/v1/quotations/{id}/sending) so a quotation created through this
 * screen can actually be used -- see docs/MIGRATION_MATRIX.md.
 */
class QuotationViewController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly BusinessPartyService $parties,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'commercial:read');
        $user = $request->user();

        $snapshot = $this->quotations->search($user, null, $request->query());
        $organisationId = $snapshot['organisation_id'];
        $partiesSnapshot = $this->parties->search($user, $organisationId, []);
        $products = Product::where('organisation_id', $organisationId)->where('status', 'ACTIVE')->orderBy('name')->get();
        $quotedValueCents = (int) Quotation::where('organisation_id', $organisationId)
            ->whereIn('status', ['ISSUED', 'ACCEPTED', 'CONVERTED'])->sum('total_cents');

        return view('quotations.index', [
            'snapshot' => $snapshot,
            'customers' => collect($partiesSnapshot['parties'])->filter(fn ($p) => $p['status'] === 'ACTIVE' && in_array('CUSTOMER', $p['relationships'], true))->values(),
            'partyCount' => $partiesSnapshot['total_count'],
            'products' => $products,
            'quotedValueCents' => $quotedValueCents,
            'canManageQuotations' => $user->hasAppPermission('quotations:manage'),
            'canConvertQuotations' => $user->hasAppPermission('quotations:manage') && $user->hasAppPermission('invoices:submit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'quotations:manage');

        try {
            $this->quotations->create($this->createPayload($request), $request->user(), (string) Str::uuid(), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('quotations.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('quotations.index')->withErrors(['quotation' => $e->getMessage()])->withInput();
        }

        return redirect()->route('quotations.index')->with('status', 'Quotation issued.');
    }

    public function edit(Request $request, string $id): View
    {
        $this->authorize('permission', 'quotations:manage');
        $user = $request->user();

        $quotation = $this->quotations->find($id, $user);
        $editPolicy = BusinessValidator::evaluateQuotationLifecycle($quotation['status'], 'EDIT', $quotation['valid_until'], now()->toDateString());
        $partiesSnapshot = $this->parties->search($user, $quotation['organisation_id'], []);
        $products = Product::where('organisation_id', $quotation['organisation_id'])->where('status', 'ACTIVE')->orderBy('name')->get();

        return view('quotations.edit', [
            'quotation' => $quotation,
            'editPolicy' => $editPolicy,
            'customers' => collect($partiesSnapshot['parties'])->filter(fn ($p) => $p['status'] === 'ACTIVE' && in_array('CUSTOMER', $p['relationships'], true))->values(),
            'products' => $products,
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'quotations:manage');

        try {
            $this->quotations->update($id, $this->editPayload($request), $request->user(), (string) Str::uuid(), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('quotations.edit', $id)->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('quotations.edit', $id)->withErrors(['quotation' => $e->getMessage()])->withInput();
        }

        return redirect()->route('quotations.index')->with('status', 'Quotation revision saved.');
    }

    public function send(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'quotations:manage');

        return $this->runTransition(fn () => $this->quotations->send($id, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null), 'Quotation sent to the customer.');
    }

    public function accept(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'quotations:manage');

        return $this->runTransition(fn () => $this->quotations->accept($id, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null), 'Quotation accepted.');
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        return $this->runTransition(fn () => $this->quotations->reject($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null), 'Quotation rejected.');
    }

    public function expire(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'quotations:manage');

        return $this->runTransition(fn () => $this->quotations->expire($id, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null), 'Quotation expired.');
    }

    public function convert(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $this->authorize('permission', 'invoices:submit');
        $correlationId = (string) Str::uuid();
        $payload = ['schema_version' => '1.0.0', 'invoice_number' => (string) $request->input('invoice_number'), 'issue_date' => (string) $request->input('issue_date')];
        $context = ['correlation_id' => $correlationId];

        try {
            $invoice = $this->quotations->convertToInvoice($id, $payload, $request->user(), (string) Str::uuid(), $context, null);
        } catch (BusinessValidationException|InvoiceValidationException $e) {
            return redirect()->route('quotations.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('quotations.index')->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', ['id' => $invoice['id'], 'created' => 1]);
    }

    private function runTransition(\Closure $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (BusinessValidationException $e) {
            return redirect()->route('quotations.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('quotations.index')->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()->route('quotations.index')->with('status', $successMessage);
    }

    /** @return array<string, mixed> */
    private function createPayload(Request $request): array
    {
        return [
            'schema_version' => '1.0.0',
            'customer_party_id' => $request->input('customer_party_id'),
            'quotation_number' => $request->input('quotation_number'),
            'currency' => 'NAD',
            'issue_date' => $request->input('issue_date'),
            'valid_until' => $request->input('valid_until'),
            'notes' => $request->input('notes') ?: null,
            'lines' => [[
                'product_id' => $request->input('product_id') ?: null,
                'description' => $request->input('description'),
                'quantity_micros' => (int) round((float) $request->input('quantity', 0) * 1_000_000),
                'unit_code' => $request->input('unit_code', 'EA'),
                'unit_price_cents' => (int) $request->input('unit_price_cents', 0),
                'tax_category' => 'STANDARD',
                'tax_rate_bps' => 1500,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function editPayload(Request $request): array
    {
        $lines = collect($request->input('lines', []))->map(fn (array $line) => [
            'product_id' => ($line['product_id'] ?? '') !== '' ? $line['product_id'] : null,
            'description' => $line['description'] ?? '',
            'quantity_micros' => (int) round((float) ($line['quantity'] ?? 0) * 1_000_000),
            'unit_code' => $line['unit_code'] ?? 'EA',
            'unit_price_cents' => (int) ($line['unit_price_cents'] ?? 0),
            'tax_category' => $line['tax_category'] ?? 'STANDARD',
            'tax_rate_bps' => (int) ($line['tax_rate_bps'] ?? 0),
        ])->values()->all();

        return [
            'schema_version' => '1.0.0',
            'customer_party_id' => $request->input('customer_party_id'),
            'quotation_number' => $request->input('quotation_number'),
            'currency' => 'NAD',
            'issue_date' => $request->input('issue_date'),
            'valid_until' => $request->input('valid_until'),
            'notes' => $request->input('notes') ?: null,
            'lines' => $lines,
        ];
    }
}
