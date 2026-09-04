<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\BusinessParty;
use App\Models\PartyRelationship;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationRevision;
use App\Models\User;
use App\Domain\Invoice\InvoiceCalculator;
use App\Services\Audit\AuditService;
use App\Services\Invoice\InvoiceService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's createQuotation/
 * sendQuotation/updateQuotation/rejectQuotation/expireQuotation/
 * acceptQuotation/convertQuotationToInvoice/searchQuotations -- Module 5
 * Phase B. Lifecycle transitions themselves are decided by
 * BusinessValidator::evaluateQuotationLifecycle, kept as pure logic
 * separate from the persistence here, exactly as the source separates
 * lib/domain/business.ts from lib/data/business-repository.ts.
 */
class QuotationService
{
    public function __construct(
        private readonly OrganisationResolver $organisations,
        private readonly InvoiceService $invoices,
        private readonly InvoiceCalculator $calculator,
    ) {}

    /** @return array<string, mixed> */
    public function create(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $quotation = BusinessValidator::quotation($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'quotation' => $quotation]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_QUOTATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $this->requirePartyRelationship($quotation['customer_party_id'], $organisation->id, 'CUSTOMER', 'Customer party');
        $this->requireOwnedBranch($quotation['branch_id'], $organisation->id);
        $duplicate = Quotation::where('organisation_id', $organisation->id)->where('quotation_number', $quotation['quotation_number'])->first();
        if ($duplicate) {
            throw new RepositoryConflictException("Quotation number already exists as {$duplicate->id}.");
        }

        $id = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($quotation, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            Quotation::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'branch_id' => $quotation['branch_id'],
                'customer_party_id' => $quotation['customer_party_id'], 'quotation_number' => $quotation['quotation_number'],
                'currency' => $quotation['currency'], 'issue_date' => $quotation['issue_date'], 'valid_until' => $quotation['valid_until'],
                'status' => 'DRAFT', 'subtotal_cents' => $quotation['subtotal_cents'], 'tax_cents' => $quotation['tax_cents'],
                'total_cents' => $quotation['total_cents'], 'notes' => $quotation['notes'], 'created_by' => $actor->id,
                'approved_by' => null, 'accepted_at' => null, 'converted_invoice_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->insertLines($id, $quotation['lines']);
            $this->recordRevision($id, $organisation->id, 1, 'CREATE', 'DRAFT', $this->snapshot($quotation, 'DRAFT'), null, $actor->id, $now);
            CommandLedger::record($actor->id, 'CREATE_QUOTATION', $idempotencyKey, $requestHash, 'QUOTATION', $id, $now);
            CommandLedger::outbox('QUOTATION', $id, 'QuotationCreated', $organisation->id, [
                'quotation_id' => $id, 'organisation_id' => $organisation->id, 'total_cents' => $quotation['total_cents'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'QUOTATION_CREATED', 'QUOTATION', $id, [
                'organisationId' => $organisation->id, 'quotationNumber' => $quotation['quotation_number'], 'totalCents' => $quotation['total_cents'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function send(string $id, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        return $this->simpleTransition($id, $actor, $idempotencyKey, $correlationId, $requestedOrganisationId, 'SEND_QUOTATION', 'SEND', 'DRAFT', 'ISSUED', 'QUOTATION_SENT', 'QuotationSent', fn (Quotation $q, $now) => ['status' => 'ISSUED', 'updated_at' => $now]);
    }

    /** @return array<string, mixed> */
    public function accept(string $id, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        return $this->simpleTransition($id, $actor, $idempotencyKey, $correlationId, $requestedOrganisationId, 'ACCEPT_QUOTATION', 'ACCEPT', 'ISSUED', 'ACCEPTED', 'QUOTATION_ACCEPTED', 'QuotationAccepted', fn (Quotation $q, $now) => ['status' => 'ACCEPTED', 'accepted_at' => $now, 'updated_at' => $now]);
    }

    /** @return array<string, mixed> */
    public function expire(string $id, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        return $this->simpleTransition($id, $actor, $idempotencyKey, $correlationId, $requestedOrganisationId, 'EXPIRE_QUOTATION', 'EXPIRE', 'ISSUED', 'EXPIRED', 'QUOTATION_EXPIRED', 'QuotationExpired', fn (Quotation $q, $now) => ['status' => 'EXPIRED', 'updated_at' => $now]);
    }

    /** @return array<string, mixed> */
    public function reject(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $rejection = BusinessValidator::quotationRejection($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'quotation_id' => $id, 'rejection' => $rejection]);
        $prior = CommandLedger::prior($actor->id, 'REJECT_QUOTATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $existing = Quotation::where('id', $id)->where('organisation_id', $organisation->id)->first();
        if (! $existing) {
            throw new BusinessResourceException('Quotation was not found in the authorised organisation.', 404);
        }
        $transition = BusinessValidator::evaluateQuotationLifecycle($existing->status, 'REJECT', $existing->valid_until->toDateString(), now()->toDateString());
        if (! $transition['allowed']) {
            throw new RepositoryConflictException($transition['reason']);
        }
        $now = now();
        DB::transaction(function () use ($existing, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId, $rejection) {
            Quotation::where('id', $id)->where('organisation_id', $organisation->id)->where('status', 'ISSUED')->update(['status' => 'REJECTED', 'updated_at' => $now]);
            CommandLedger::record($actor->id, 'REJECT_QUOTATION', $idempotencyKey, $requestHash, 'QUOTATION', $id, $now);
            CommandLedger::outbox('QUOTATION', $id, 'QuotationRejected', $organisation->id, ['quotation_id' => $id, 'organisation_id' => $organisation->id, 'reason_recorded' => true, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'QUOTATION_REJECTED', 'QUOTATION', $id, ['organisationId' => $organisation->id, 'reason' => $rejection['reason'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function update(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $quotation = BusinessValidator::quotation($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'quotation_id' => $id, 'quotation' => $quotation]);
        $prior = CommandLedger::prior($actor->id, 'UPDATE_QUOTATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $existing = Quotation::where('id', $id)->where('organisation_id', $organisation->id)->first();
        if (! $existing) {
            throw new BusinessResourceException('Quotation was not found in the authorised organisation.', 404);
        }
        $transition = BusinessValidator::evaluateQuotationLifecycle($existing->status, 'EDIT', $existing->valid_until->toDateString(), now()->toDateString());
        if (! $transition['allowed']) {
            throw new RepositoryConflictException($transition['reason']);
        }
        if ($quotation['quotation_number'] !== $existing->quotation_number) {
            throw new RepositoryConflictException('Quotation number is immutable after issue.');
        }
        $this->requirePartyRelationship($quotation['customer_party_id'], $organisation->id, 'CUSTOMER', 'Customer party');
        $this->requireOwnedBranch($quotation['branch_id'], $organisation->id);

        $now = now();
        DB::transaction(function () use ($quotation, $existing, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            $priorRevision = QuotationRevision::where('quotation_id', $id)->orderByDesc('revision_number')->first();
            $revisionNumber = ($priorRevision->revision_number ?? 0) + 1;
            $previousHash = $priorRevision->snapshot_hash ?? null;
            if (! $priorRevision) {
                $existingLines = QuotationLine::where('quotation_id', $id)->orderBy('line_number')->get();
                $previousHash = $this->recordRevision($id, $organisation->id, 1, 'CREATE', $existing->status, $this->storedSnapshot($existing, $existingLines), null, $existing->created_by, $existing->created_at);
                $revisionNumber = 2;
            }
            $this->recordRevision($id, $organisation->id, $revisionNumber, 'EDIT', $existing->status, $this->snapshot($quotation, $existing->status), $previousHash, $actor->id, $now);

            Quotation::where('id', $id)->where('organisation_id', $organisation->id)->where('status', $existing->status)->update([
                'branch_id' => $quotation['branch_id'], 'customer_party_id' => $quotation['customer_party_id'], 'currency' => $quotation['currency'],
                'issue_date' => $quotation['issue_date'], 'valid_until' => $quotation['valid_until'], 'subtotal_cents' => $quotation['subtotal_cents'],
                'tax_cents' => $quotation['tax_cents'], 'total_cents' => $quotation['total_cents'], 'notes' => $quotation['notes'], 'updated_at' => $now,
            ]);
            QuotationLine::where('quotation_id', $id)->delete();
            $this->insertLines($id, $quotation['lines']);

            CommandLedger::record($actor->id, 'UPDATE_QUOTATION', $idempotencyKey, $requestHash, 'QUOTATION', $id, $now);
            CommandLedger::outbox('QUOTATION', $id, 'QuotationEdited', $organisation->id, [
                'quotation_id' => $id, 'organisation_id' => $organisation->id, 'revision_number' => $revisionNumber, 'total_cents' => $quotation['total_cents'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'QUOTATION_EDITED', 'QUOTATION', $id, [
                'organisationId' => $organisation->id, 'revisionNumber' => $revisionNumber, 'previousTotalCents' => (int) $existing->total_cents, 'totalCents' => $quotation['total_cents'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function search(User $actor, ?string $requestedOrganisationId, array $params): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $query = BusinessValidator::quotationSearchQuery($params);

        $builder = Quotation::where('organisation_id', $organisation->id);
        if ($query['status']) {
            $builder->where('status', $query['status']);
        }
        if ($query['customer_party_id']) {
            $builder->where('customer_party_id', $query['customer_party_id']);
        }
        if ($query['q']) {
            $like = '%'.$query['q'].'%';
            $builder->where(function ($sub) use ($like) {
                $sub->where('quotation_number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('display_name', 'like', $like));
            });
        }

        $totalCount = (clone $builder)->count();
        $quotations = $builder->orderByDesc('issue_date')->orderByDesc('created_at')->limit($query['limit'])->offset($query['offset'])->get()
            ->map(fn (Quotation $q) => $this->present($q))->values()->all();

        return ['organisation_id' => $organisation->id, 'quotations' => $quotations, 'total_count' => $totalCount, 'limit' => $query['limit'], 'offset' => $query['offset']];
    }

    /**
     * Module 5 Phase B ConvertQuotationToInvoice: an ACCEPTED quotation
     * becomes a real, certified TAX_INVOICE via InvoiceService::submit --
     * the exact same idempotent, VAT-rule-resolving, atomically-written
     * certification pipeline Phase 9 already built and verified, reused
     * unchanged here rather than duplicated.
     *
     * @return array<string, mixed> the certified invoice (InvoiceService::find's shape)
     */
    public function convertToInvoice(string $id, array $payload, User $actor, string $idempotencyKey, array $context, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $conversion = BusinessValidator::quotationConversion($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'quotation_id' => $id, 'conversion' => $conversion]);
        $prior = CommandLedger::prior($actor->id, 'CONVERT_QUOTATION', $idempotencyKey, $requestHash);
        if ($prior) {
            $existing = $this->invoices->find($prior, $actor);
            if (! $existing) {
                throw new RepositoryConflictException('The prior quotation-conversion response is unavailable.');
            }

            return $existing;
        }

        $quotation = Quotation::with(['customer', 'organisation.taxpayer'])->where('id', $id)->where('organisation_id', $organisation->id)->first();
        if (! $quotation) {
            throw new BusinessResourceException('Quotation was not found in the authorised organisation.', 404);
        }
        if ($quotation->converted_invoice_id) {
            $existing = $this->invoices->find($quotation->converted_invoice_id, $actor);
            if (! $existing) {
                throw new RepositoryConflictException('The converted invoice is no longer available.');
            }
            if ($existing['invoiceNumber'] !== $conversion['invoice_number'] || $existing['issueDate'] !== $conversion['issue_date']) {
                throw new RepositoryConflictException("Quotation was already converted to invoice {$existing['invoiceNumber']}.");
            }

            return $existing;
        }
        $transition = BusinessValidator::evaluateQuotationLifecycle($quotation->status, 'CONVERT', $quotation->valid_until->toDateString(), now()->toDateString());
        if (! $transition['allowed']) {
            throw new RepositoryConflictException($transition['reason']);
        }
        if ($conversion['issue_date'] < $quotation->issue_date->toDateString()) {
            throw new RepositoryConflictException('The invoice cannot be issued before the quotation.');
        }

        $lines = QuotationLine::where('quotation_id', $quotation->id)->orderBy('line_number')->get();
        if ($lines->isEmpty()) {
            throw new RepositoryConflictException('The quotation has no lines and cannot be converted.');
        }

        $customerIdentifier = $quotation->customer->vat_number
            ? ['type' => 'VAT_NUMBER', 'value' => $quotation->customer->vat_number]
            : ($quotation->customer->tin
                ? ['type' => 'TIN', 'value' => $quotation->customer->tin]
                : ['type' => 'OTHER', 'value' => $quotation->customer_party_id]);
        $submittedAt = optional($quotation->accepted_at)->toISOString() ?? ($quotation->issue_date->toDateString().'T00:00:00.000Z');

        $invoicePayload = [
            'schema_version' => '1.0.0', 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'VAT-MSA-QUOTATION', 'document_id' => $quotation->id, 'submitted_at' => $submittedAt],
            'supplier' => ['name' => $quotation->organisation->taxpayer->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $quotation->organisation->taxpayer->vat_number]]],
            'customer' => ['name' => $quotation->customer->display_name, 'identifiers' => [$customerIdentifier]],
            'invoice_number' => $conversion['invoice_number'], 'issue_date' => $conversion['issue_date'],
            'due_date' => $conversion['due_date'], 'currency' => $quotation->currency,
            'lines' => $lines->map(fn (QuotationLine $line) => [
                'line_number' => $line->line_number, 'item_code' => $line->product_id, 'description' => $line->description,
                'quantity' => $this->microsToDecimal((int) $line->quantity_micros), 'unit_code' => $line->unit_code,
                'unit_price' => $this->calculator->centsToDecimal((int) $line->unit_price_cents),
                'net_amount' => $this->calculator->centsToDecimal((int) $line->net_amount_cents),
                'tax' => [
                    'category' => $line->tax_category === 'OUT_OF_SCOPE' ? 'OUTSIDE_SCOPE' : $line->tax_category,
                    'rate' => $this->calculator->centsToDecimal((int) $line->tax_rate_bps),
                    'taxable_amount' => $this->calculator->centsToDecimal((int) $line->net_amount_cents),
                    'tax_amount' => $this->calculator->centsToDecimal((int) $line->tax_amount_cents),
                ],
            ])->values()->all(),
            'totals' => [
                'line_net_amount' => $this->calculator->centsToDecimal((int) $quotation->subtotal_cents),
                'tax_exclusive_amount' => $this->calculator->centsToDecimal((int) $quotation->subtotal_cents),
                'tax_amount' => $this->calculator->centsToDecimal((int) $quotation->tax_cents),
                'tax_inclusive_amount' => $this->calculator->centsToDecimal((int) $quotation->total_cents),
                'payable_amount' => $this->calculator->centsToDecimal((int) $quotation->total_cents),
            ],
        ];

        // Invoice certification is independently idempotent (Phase 9). If this process
        // stops after that commit, the same key reloads the certified invoice and safely
        // finishes quotation linkage below.
        $invoice = $this->invoices->submit($invoicePayload, $actor, $idempotencyKey, $context);
        $now = now();
        DB::transaction(function () use ($quotation, $organisation, $actor, $id, $invoice, $now, $idempotencyKey, $requestHash, $context) {
            Quotation::where('id', $id)->where('organisation_id', $organisation->id)->where('status', 'ACCEPTED')->update([
                'status' => 'CONVERTED', 'converted_invoice_id' => $invoice['id'], 'updated_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CONVERT_QUOTATION', $idempotencyKey, $requestHash, 'INVOICE', $invoice['id'], $now);
            CommandLedger::outbox('QUOTATION', $id, 'QuotationConverted', $organisation->id, [
                'quotation_id' => $id, 'organisation_id' => $organisation->id, 'invoice_id' => $invoice['id'], 'correlation_id' => $context['correlation_id'] ?? null,
            ], $now);
            AuditService::append($actor, 'QUOTATION_CONVERTED', 'QUOTATION', $id, [
                'organisationId' => $organisation->id, 'invoiceId' => $invoice['id'], 'correlationId' => $context['correlation_id'] ?? null,
            ], $now);
        });

        return $invoice;
    }

    // -- internals --

    private function simpleTransition(string $id, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId, string $commandType, string $action, string $fromStatus, string $toStatus, string $auditAction, string $eventType, \Closure $updateFields): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'quotation_id' => $id, 'action' => $action]);
        $prior = CommandLedger::prior($actor->id, $commandType, $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $existing = Quotation::where('id', $id)->where('organisation_id', $organisation->id)->first();
        if (! $existing) {
            throw new BusinessResourceException('Quotation was not found in the authorised organisation.', 404);
        }
        $transition = BusinessValidator::evaluateQuotationLifecycle($existing->status, $action, $existing->valid_until->toDateString(), now()->toDateString());
        if (! $transition['allowed']) {
            throw new RepositoryConflictException($transition['reason']);
        }
        $now = now();
        DB::transaction(function () use ($existing, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId, $fromStatus, $commandType, $eventType, $auditAction, $updateFields) {
            Quotation::where('id', $id)->where('organisation_id', $organisation->id)->where('status', $fromStatus)->update($updateFields($existing, $now));
            if ($commandType === 'SEND_QUOTATION') {
                $lines = QuotationLine::where('quotation_id', $id)->orderBy('line_number')->get();
                $priorRevision = QuotationRevision::where('quotation_id', $id)->orderByDesc('revision_number')->first();
                $this->recordRevision($id, $organisation->id, ($priorRevision->revision_number ?? 0) + 1, 'SEND', 'ISSUED', $this->storedSnapshot($existing, $lines, 'ISSUED'), $priorRevision->snapshot_hash ?? null, $actor->id, $now);
            }
            CommandLedger::record($actor->id, $commandType, $idempotencyKey, $requestHash, 'QUOTATION', $id, $now);
            CommandLedger::outbox('QUOTATION', $id, $eventType, $organisation->id, ['quotation_id' => $id, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, $auditAction, 'QUOTATION', $id, ['organisationId' => $organisation->id, 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    private function requirePartyRelationship(string $partyId, string $organisationId, string $relationship, string $label): void
    {
        $row = BusinessParty::where('business_parties.id', $partyId)->where('business_parties.organisation_id', $organisationId)->where('business_parties.status', 'ACTIVE')
            ->whereHas('relationships', fn ($q) => $q->where('relationship', $relationship)->where('status', 'ACTIVE'))
            ->first();
        if (! $row) {
            throw new BusinessResourceException("{$label} is not an active ".mb_strtolower($relationship)." in the authorised organisation.", 422);
        }
    }

    private function requireOwnedBranch(?string $branchId, string $organisationId): void
    {
        if (! $branchId) {
            return;
        }
        $exists = DB::table('branches')->where('id', $branchId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Branch does not exist in the authorised organisation.', 422);
        }
    }

    private function insertLines(string $quotationId, array $lines): void
    {
        foreach ($lines as $line) {
            QuotationLine::create([
                'id' => (string) Str::uuid(), 'quotation_id' => $quotationId, 'line_number' => $line['line_number'],
                'product_id' => $line['product_id'], 'description' => $line['description'], 'quantity_micros' => $line['quantity_micros'],
                'unit_code' => $line['unit_code'], 'unit_price_cents' => $line['unit_price_cents'], 'net_amount_cents' => $line['net_amount_cents'],
                'tax_category' => $line['tax_category'], 'tax_rate_bps' => $line['tax_rate_bps'], 'tax_amount_cents' => $line['tax_amount_cents'],
            ]);
        }
    }

    /** Records one quotation_revisions row; returns its snapshot_hash for the next revision to chain onto. */
    private function recordRevision(string $quotationId, string $organisationId, int $revisionNumber, string $action, string $status, array $snapshot, ?string $previousHash, string $actorId, \DateTimeInterface $now): string
    {
        $encodedSnapshot = AuditService::canonicalJson(['previous_hash' => $previousHash, 'state' => $snapshot]);
        $hash = hash('sha256', $encodedSnapshot);
        QuotationRevision::create([
            'id' => (string) Str::uuid(), 'quotation_id' => $quotationId, 'organisation_id' => $organisationId,
            'revision_number' => $revisionNumber, 'action' => $action, 'status' => $status, 'snapshot_hash' => $hash,
            'snapshot' => $encodedSnapshot, 'created_by' => $actorId, 'created_at' => $now,
        ]);

        return $hash;
    }

    /** @return array<string, mixed> */
    private function snapshot(array $quotation, string $status): array
    {
        return [
            'schema_version' => $quotation['schema_version'], 'customer_party_id' => $quotation['customer_party_id'],
            'branch_id' => $quotation['branch_id'], 'quotation_number' => $quotation['quotation_number'], 'currency' => $quotation['currency'],
            'issue_date' => $quotation['issue_date'], 'valid_until' => $quotation['valid_until'], 'status' => $status,
            'notes' => $quotation['notes'], 'lines' => $quotation['lines'], 'subtotal_cents' => $quotation['subtotal_cents'],
            'tax_cents' => $quotation['tax_cents'], 'total_cents' => $quotation['total_cents'],
        ];
    }

    /** @param \Illuminate\Support\Collection<int, QuotationLine> $lines */
    private function storedSnapshot(Quotation $quotation, $lines, ?string $statusOverride = null): array
    {
        return [
            'schema_version' => '1.0.0', 'customer_party_id' => $quotation->customer_party_id, 'branch_id' => $quotation->branch_id,
            'quotation_number' => $quotation->quotation_number, 'currency' => $quotation->currency,
            'issue_date' => $quotation->issue_date->toDateString(), 'valid_until' => $quotation->valid_until->toDateString(),
            'status' => $statusOverride ?? $quotation->status, 'notes' => $quotation->notes,
            'lines' => $lines->map(fn (QuotationLine $l) => [
                'line_number' => $l->line_number, 'product_id' => $l->product_id, 'description' => $l->description,
                'quantity_micros' => (int) $l->quantity_micros, 'unit_code' => $l->unit_code, 'unit_price_cents' => (int) $l->unit_price_cents,
                'net_amount_cents' => (int) $l->net_amount_cents, 'tax_category' => $l->tax_category, 'tax_rate_bps' => (int) $l->tax_rate_bps,
                'tax_amount_cents' => (int) $l->tax_amount_cents,
            ])->values()->all(),
            'subtotal_cents' => (int) $quotation->subtotal_cents, 'tax_cents' => (int) $quotation->tax_cents, 'total_cents' => (int) $quotation->total_cents,
        ];
    }

    private function microsToDecimal(int $micros): string
    {
        $whole = intdiv($micros, 1_000_000);
        $fraction = rtrim(str_pad((string) ($micros % 1_000_000), 6, '0', STR_PAD_LEFT), '0');

        return $fraction !== '' ? "{$whole}.{$fraction}" : (string) $whole;
    }

    /**
     * Ported from lib/data/business-repository.ts's getQuotationForEdit --
     * a single-record read, exposed as a public service method the same way
     * App\Services\Invoice\InvoiceService::find is (this file's own
     * findOrFail/present already did all the work; this just makes them
     * reachable for the new Blade edit view, matching the source's own
     * server-component-only, non-JSON-API data function). Includes
     * revision_count, matching the source's own COUNT(*) subquery -- search()
     * deliberately does not carry it, matching source (searchQuotations
     * itself never selects it either).
     *
     * @return array<string, mixed>
     */
    public function find(string $id, User $actor, ?string $requestedOrganisationId = null): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $quotation = $this->findOrFail($id, $organisation->id);
        $quotation['revision_count'] = QuotationRevision::where('quotation_id', $id)->count();

        return $quotation;
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $id, string $organisationId): array
    {
        $quotation = Quotation::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $quotation) {
            throw new BusinessResourceException('Quotation was not found in the authorised organisation.', 404);
        }

        return $this->present($quotation);
    }

    /**
     * Ported from lib/data/business-repository.ts's searchQuotations/
     * getQuotationForEdit -- both join business_parties for `customer_name`
     * (`p.display_name AS customer_name`), a field this port's present()
     * omitted until now; closing that gap here benefits every caller
     * (JSON API and the new Blade views alike), not a second query path.
     *
     * @return array<string, mixed>
     */
    private function present(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id, 'organisation_id' => $quotation->organisation_id, 'branch_id' => $quotation->branch_id,
            'customer_party_id' => $quotation->customer_party_id, 'customer_name' => optional($quotation->customer)->display_name,
            'quotation_number' => $quotation->quotation_number,
            'currency' => $quotation->currency, 'issue_date' => $quotation->issue_date->toDateString(),
            'valid_until' => $quotation->valid_until->toDateString(), 'status' => $quotation->status,
            'subtotal_cents' => (int) $quotation->subtotal_cents, 'tax_cents' => (int) $quotation->tax_cents, 'total_cents' => (int) $quotation->total_cents,
            'notes' => $quotation->notes, 'created_by' => $quotation->created_by, 'approved_by' => $quotation->approved_by,
            'accepted_at' => optional($quotation->accepted_at)->toISOString(), 'converted_invoice_id' => $quotation->converted_invoice_id,
            'created_at' => optional($quotation->created_at)->toISOString(), 'updated_at' => optional($quotation->updated_at)->toISOString(),
            'lines' => QuotationLine::where('quotation_id', $quotation->id)->orderBy('line_number')->get()->map(fn (QuotationLine $l) => [
                'line_number' => $l->line_number, 'product_id' => $l->product_id, 'description' => $l->description,
                'quantity_micros' => (int) $l->quantity_micros, 'unit_code' => $l->unit_code, 'unit_price_cents' => (int) $l->unit_price_cents,
                'net_amount_cents' => (int) $l->net_amount_cents, 'tax_category' => $l->tax_category, 'tax_rate_bps' => (int) $l->tax_rate_bps,
                'tax_amount_cents' => (int) $l->tax_amount_cents,
            ])->values()->all(),
        ];
    }
}
