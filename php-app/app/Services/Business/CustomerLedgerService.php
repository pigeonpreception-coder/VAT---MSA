<?php

namespace App\Services\Business;

use App\Exceptions\BusinessResourceException;
use App\Models\BusinessParty;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Collection;

/**
 * Backs the "Customer Ledger" sidebar item (routes/web.php's own
 * `$plannedRoute('/accounting/customer-ledger', ...)` until now), the
 * receivables-side twin of SupplierLedgerService -- same reasoning applies
 * here, read in full first: nothing posts a JournalEntry on a sale either,
 * and journal_lines has no customer attribution column regardless. The
 * real receivable-recognition event on this platform is
 * QuotationService::convertToInvoice() (Quotation.status ACCEPTED ->
 * CONVERTED, with a real certified App\Models\Invoice created via
 * InvoiceService::submit and linked back via converted_invoice_id) -- a
 * CONVERTED quotation is a genuine recognised amount owed by that
 * customer, the direct receivables-side counterpart of an APPROVED
 * expense being a recognised amount owed to a supplier. ACCEPTED (the
 * customer has committed but no invoice has been raised yet) is shown as
 * a separate pending figure, the same "not yet a recognised balance"
 * reasoning SupplierLedgerService gives SUBMITTED expenses. Every
 * Quotation carries a mandatory (non-nullable) customer_party_id, so --
 * unlike Expense.supplier_party_id -- there is no "unassigned" bucket to
 * account for here.
 */
class CustomerLedgerService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** Every active CUSTOMER-relationship party in scope, for the ledger's own customer picker. */
    public function customerOptions(User $actor, ?string $requestedOrganisationId): Collection
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        return BusinessParty::where('organisation_id', $organisation->id)
            ->whereHas('relationships', fn ($q) => $q->where('relationship', 'CUSTOMER')->where('status', 'ACTIVE'))
            ->orderBy('display_name')->get();
    }

    /** @return array<string, mixed> */
    public function summary(User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        $rows = Quotation::query()
            ->join('business_parties', 'business_parties.id', '=', 'quotations.customer_party_id')
            ->where('quotations.organisation_id', $organisation->id)
            ->whereIn('quotations.status', ['CONVERTED', 'ACCEPTED'])
            ->groupBy('business_parties.id', 'business_parties.display_name', 'business_parties.vat_number', 'business_parties.status')
            ->orderByDesc('posted_total_cents')
            ->selectRaw(
                'business_parties.id as customer_party_id, business_parties.display_name, business_parties.vat_number, business_parties.status as customer_status, '.
                "COUNT(CASE WHEN quotations.status = 'CONVERTED' THEN 1 END) as posted_count, ".
                "COALESCE(SUM(CASE WHEN quotations.status = 'CONVERTED' THEN quotations.total_cents ELSE 0 END), 0) as posted_total_cents, ".
                "COUNT(CASE WHEN quotations.status = 'ACCEPTED' THEN 1 END) as pending_count, ".
                "COALESCE(SUM(CASE WHEN quotations.status = 'ACCEPTED' THEN quotations.total_cents ELSE 0 END), 0) as pending_total_cents, ".
                'MAX(quotations.issue_date) as last_quotation_date',
            )->get();

        $customers = $rows->map(fn ($row) => [
            'customer_party_id' => $row->customer_party_id, 'display_name' => $row->display_name, 'vat_number' => $row->vat_number,
            'customer_status' => $row->customer_status, 'posted_count' => (int) $row->posted_count, 'posted_total_cents' => (int) $row->posted_total_cents,
            'pending_count' => (int) $row->pending_count, 'pending_total_cents' => (int) $row->pending_total_cents,
            'last_quotation_date' => $row->last_quotation_date,
        ])->values()->all();

        return [
            'organisation_id' => $organisation->id,
            'customers' => $customers,
            'total_posted_cents' => array_sum(array_column($customers, 'posted_total_cents')),
            'total_pending_cents' => array_sum(array_column($customers, 'pending_total_cents')),
        ];
    }

    /**
     * One customer's own statement: every quotation raised against them
     * (CONVERTED, ACCEPTED, ISSUED, REJECTED or EXPIRED, oldest first, for
     * full transparency), with a running posted balance that only
     * CONVERTED lines move -- a still-open, rejected or expired quotation
     * is shown but never changes the running total, the same "not yet a
     * recognised balance" reasoning as summary()'s own pending figure.
     *
     * @return array<string, mixed>
     */
    public function statement(User $actor, ?string $requestedOrganisationId, string $customerPartyId): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        $customer = BusinessParty::where('id', $customerPartyId)->where('organisation_id', $organisation->id)->first();
        if (! $customer) {
            throw new BusinessResourceException('Customer was not found in the authorised organisation.', 404);
        }

        $quotations = Quotation::where('organisation_id', $organisation->id)->where('customer_party_id', $customerPartyId)
            ->whereIn('status', ['CONVERTED', 'ACCEPTED', 'ISSUED', 'REJECTED', 'EXPIRED'])
            ->orderBy('issue_date')->orderBy('created_at')
            ->get();

        $runningCents = 0;
        $lines = $quotations->map(function (Quotation $quotation) use (&$runningCents) {
            if ($quotation->status === 'CONVERTED') {
                $runningCents += (int) $quotation->total_cents;
            }

            return [
                'id' => $quotation->id, 'quotation_number' => $quotation->quotation_number, 'issue_date' => $quotation->issue_date->toDateString(),
                'notes' => $quotation->notes, 'total_cents' => (int) $quotation->total_cents, 'status' => $quotation->status,
                'converted_invoice_id' => $quotation->converted_invoice_id,
                'running_balance_cents' => $quotation->status === 'CONVERTED' ? $runningCents : null,
            ];
        })->values()->all();

        return [
            'organisation_id' => $organisation->id,
            'customer' => ['id' => $customer->id, 'display_name' => $customer->display_name, 'legal_name' => $customer->legal_name, 'vat_number' => $customer->vat_number, 'status' => $customer->status],
            'lines' => $lines, 'posted_balance_cents' => $runningCents,
        ];
    }
}
