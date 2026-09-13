<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\BusinessValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Integration\PosApiClientService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * User's own explicit request: local (domestic) invoices, issued or
 * received either through a taxpayer's own private Point-of-Sale system
 * (a real, credential-authenticated API push -- see
 * App\Http\Controllers\Integration\PosInvoiceController and
 * App\Services\Integration\PosApiClientService) or through VAT-MSA's own
 * built-in POS module (App\Services\Operations\PosService, which already
 * calls App\Services\Invoice\InvoiceService::submit() directly and needs
 * no API at all), must be visible together in real time with their
 * cross-party match status -- the real-time "NamRA autonomous invoice
 * match" the request describes is exactly the existing MATCHED/CERTIFIED/
 * EXCEPTION status InvoiceService::submit() already computes the instant
 * either path certifies an invoice, and the VAT return/refund pipeline
 * (App\Services\VatLifecycle\VatLifecycleService::generateReturn()) has
 * always read that same ledger data live -- nothing new was needed there.
 *
 * Replaces the former `invoice-management.local` planned-module
 * placeholder, whose own scope-note framed the local/foreign split as
 * blocked on a missing counterparty-country field. That framing predates
 * the Foreign Invoices feature: foreign invoices are now their own
 * customs-declaration-backed register (App\Models\ImportRecord, App\Http\
 * Controllers\Business\ForeignInvoiceViewController) entirely separate
 * from this domestic App\Models\Invoice pipeline, so nothing here needed
 * a new country field after all -- every Invoice row already is a local
 * one by construction; credit/debit notes are already the same Invoice
 * rows (document_type CREDIT_NOTE/DEBIT_NOTE) and need no separate
 * handling.
 */
class LocalInvoiceViewController extends Controller
{
    public function __construct(
        private readonly PosApiClientService $posClients,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'invoices:read');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));
        $taxpayerId = $organisation->taxpayer_id;

        $issued = Invoice::where('supplier_taxpayer_id', $taxpayerId)->orderByDesc('issue_date')->orderByDesc('certified_at')->limit(100)->get();
        $received = Invoice::where('customer_taxpayer_id', $taxpayerId)->orderByDesc('issue_date')->orderByDesc('certified_at')->limit(100)->get();

        $canManageIntegrations = $user->hasAppPermission('integrations:manage');

        return view('invoice-management.local', [
            'issued' => $this->present($issued),
            'received' => $this->present($received),
            'canManageIntegrations' => $canManageIntegrations,
            'apiClients' => $canManageIntegrations ? $this->posClients->list($organisation) : collect(),
            'newCredential' => session('newCredential'),
        ]);
    }

    public function storeCredential(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'integrations:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        try {
            $result = $this->posClients->issue($organisation, $user, (string) $request->input('name'), $this->formIdempotencyKey($request));
        } catch (BusinessValidationException $e) {
            return redirect()->route('invoice-management.local')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (RepositoryConflictException $e) {
            return redirect()->route('invoice-management.local')->withErrors(['name' => $e->getMessage()])->withInput();
        }

        if ($result['replayed']) {
            return redirect()->route('invoice-management.local')
                ->with('status', 'This credential was already issued in your last request -- its secret was shown once and cannot be retrieved again. Revoke it and issue a new one if the secret was lost.');
        }

        return redirect()->route('invoice-management.local')
            ->with('status', 'POS API credential created. Copy the secret now -- it will not be shown again.')
            ->with('newCredential', $result);
    }

    public function revokeCredential(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'integrations:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        try {
            $this->posClients->revoke($organisation, $id, $user, (string) $request->input('reason'), $this->formIdempotencyKey($request));
        } catch (BusinessValidationException $e) {
            return redirect()->route('invoice-management.local')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (RepositoryConflictException $e) {
            return redirect()->route('invoice-management.local')->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('invoice-management.local')->with('status', 'POS API credential revoked.');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function present(\Illuminate\Database\Eloquent\Collection $invoices): Collection
    {
        return $invoices->map(fn (Invoice $invoice) => [
            'id' => $invoice->id, 'invoiceNumber' => $invoice->invoice_number, 'documentType' => $invoice->document_type,
            'supplierName' => $invoice->supplier_name, 'customerName' => $invoice->customer_name,
            'issueDate' => $invoice->issue_date->toDateString(), 'currency' => $invoice->currency,
            'totalCents' => (int) $invoice->total_cents, 'status' => $invoice->status,
            'sourceLabel' => $this->sourceLabel($invoice->source_system),
        ])->values();
    }

    private function sourceLabel(string $sourceSystem): string
    {
        return match (true) {
            $sourceSystem === 'VAT-MSA-POS' => 'VAT-MSA POS',
            str_starts_with($sourceSystem, 'EXTERNAL-POS:') => 'External POS (API)',
            default => 'Manual / other system',
        };
    }
}
