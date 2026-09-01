<?php

namespace App\Services\Invoice;

use App\Domain\Invoice\InvoiceCalculator;
use App\Exceptions\InvoiceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\Certificate;
use App\Models\IdempotencyRecord;
use App\Models\Invoice;
use App\Models\InvoiceCorrection;
use App\Models\InvoiceLine;
use App\Models\LedgerEntry;
use App\Models\OutboxEvent;
use App\Models\ReconciliationException;
use App\Models\SecurityEvent;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatRule;
use App\Models\VatTransaction;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Invoice\VatRuleResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/repository.ts's submitInvoice/getInvoiceById/
 * listInvoices/cancelInvoice/explainInvoiceVat/getTransactionTimeline
 * (Module 2 Phases A-E, in full). Covers: certification of TAX_INVOICE/
 * SIMPLIFIED_TAX_INVOICE/SELF_BILLED_INVOICE/CREDIT_NOTE/DEBIT_NOTE,
 * idempotent replay, VAT-rule resolution, correction lineage and the
 * ledger/audit/outbox/security-event side effects, officer-only
 * cancellation (with its own reversing ledger entries), per-line VAT-rule
 * explanation, and the full cross-invoice transaction timeline. The
 * standalone VAT-rule evaluate/propose/approve routes
 * (lib/data/vat-rule-repository.ts's other exports) remain deferred -- see
 * docs/MIGRATION_MATRIX.md.
 */
class InvoiceService
{
    public function __construct(private readonly InvoiceCalculator $calculator) {}

    /**
     * @param array<string, mixed> $payload
     * @param array{correlation_id?: ?string, device_id?: ?string, source_token?: ?string} $context
     * @return array<string, mixed>
     */
    public function submit(array $payload, User $actor, string $idempotencyKey, array $context = []): array
    {
        if (mb_strlen($idempotencyKey) < 16 || mb_strlen($idempotencyKey) > 128) {
            throw new InvoiceValidationException([
                ['code' => 'IDEMPOTENCY_KEY_INVALID', 'path' => '/headers/idempotency-key', 'message' => 'Idempotency key must contain 16 to 128 characters.'],
            ]);
        }

        $calculated = $this->calculator->calculateAndValidate($payload);
        $requestHash = hash('sha256', AuditService::canonicalJson($payload));

        $prior = IdempotencyRecord::where('actor_id', $actor->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            if ($prior->request_hash !== $requestHash) {
                throw new RepositoryConflictException('The idempotency key was already used for a different invoice payload.');
            }
            $existing = $this->find($prior->response_invoice_id, $actor);
            if (! $existing) {
                throw new RepositoryConflictException('The prior idempotent response is unavailable.');
            }
            return $existing;
        }

        // Module 2 Phase A: every line's tax rate must resolve to a NamRA-approved
        // VatRule for its category as of the invoice's issue date -- fails closed
        // (no rule bound) rather than trusting the client-supplied rate, which is
        // all InvoiceCalculator::calculateAndValidate checks (internal arithmetic
        // consistency only, not statutory correctness).
        $vatRuleIdByLineNumber = [];
        foreach ($calculated['lines'] as $line) {
            $category = $line['tax']['category'];
            $rule = $this->applicableVatRule($category, $payload['issue_date']);
            if (! $rule) {
                throw new InvoiceValidationException([
                    ['code' => 'NO_APPROVED_VAT_RULE', 'path' => '/lines/'.($line['line_number'] - 1).'/tax/category', 'message' => "No approved VAT rule is bound for {$category} on {$payload['issue_date']}."],
                ]);
            }
            if ((int) $rule->rate_bps !== $line['taxRateBps']) {
                $expected = number_format($rule->rate_bps / 100, 2);
                $received = number_format($line['taxRateBps'] / 100, 2);
                throw new InvoiceValidationException([
                    ['code' => 'VAT_RATE_RULE_MISMATCH', 'path' => '/lines/'.($line['line_number'] - 1).'/tax/rate', 'message' => "{$category} must use {$expected}% per approved rule version {$rule->version} (received {$received}%)."],
                ]);
            }
            $vatRuleIdByLineNumber[$line['line_number']] = $rule->id;
        }

        $supplierVat = $this->calculator->getVatNumber($payload['supplier']);
        $customerVat = $this->calculator->getVatNumber($payload['customer']);
        $now = now();

        $supplier = $this->resolveCapableTaxpayer($supplierVat, 'SELLER', $now);
        if (! $supplier) {
            throw new InvoiceValidationException([
                ['code' => 'SUPPLIER_NOT_AUTHORISED', 'path' => '/supplier/identifiers', 'message' => 'Supplier VAT number does not resolve to an active organisation with seller capability.'],
            ]);
        }
        TenantScope::requireTaxpayer($actor, $supplier->id);

        $customer = $customerVat ? $this->resolveCapableTaxpayer($customerVat, 'BUYER', $now) : null;

        $documentType = $payload['document_type'];
        $isCorrection = in_array($documentType, ['CREDIT_NOTE', 'DEBIT_NOTE'], true);
        $originalInvoice = $isCorrection ? $this->resolveOriginalInvoice($payload, $supplier, $customer, $customerVat, $calculated) : null;

        $duplicate = Invoice::where('supplier_taxpayer_id', $supplier->id)
            ->where('source_system', $payload['source']['system_id'])
            ->where('source_document_id', $payload['source']['document_id'])
            ->first();
        if ($duplicate) {
            throw new RepositoryConflictException("Source document already exists as invoice {$duplicate->id}.");
        }
        $invoiceNumber = trim($payload['invoice_number']);
        // Module 2 Phase B: invoice_number must be unique per supplier -- the invoices
        // table's own UNIQUE(supplier_taxpayer_id, invoice_number) is the backstop; this
        // explicit check gives a clearer error than the raw constraint violation for the
        // common, non-racing case.
        $numberCollision = Invoice::where('supplier_taxpayer_id', $supplier->id)->where('invoice_number', $invoiceNumber)->first();
        if ($numberCollision) {
            throw new RepositoryConflictException("Invoice number {$invoiceNumber} has already been used by this supplier.");
        }

        $invoiceId = (string) Str::uuid();
        $transactionId = (string) Str::uuid();
        $certificateId = (string) Str::uuid();
        $verificationToken = 'vfy_'.str_replace('-', '', (string) Str::uuid());
        $risk = $this->calculator->score($payload, $calculated, (bool) $customer);
        $status = in_array($risk['level'], ['HIGH', 'CRITICAL'], true) ? 'EXCEPTION' : ($customer ? 'MATCHED' : 'CERTIFIED');
        $signature = 'DEV.'.hash('sha256', "{$requestHash}:{$certificateId}:{$now->toISOString()}");
        $period = mb_substr($payload['issue_date'], 0, 7);

        try {
            DB::transaction(function () use (
                $payload, $calculated, $actor, $idempotencyKey, $requestHash, $supplier, $customer, $customerVat, $supplierVat,
                $originalInvoice, $documentType, $invoiceId, $invoiceNumber, $transactionId, $certificateId, $verificationToken,
                $risk, $status, $signature, $period, $now, $vatRuleIdByLineNumber, $context,
            ) {
                Invoice::create([
                    'id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'document_type' => $documentType,
                    'source_system' => trim($payload['source']['system_id']), 'source_document_id' => trim($payload['source']['document_id']),
                    'supplier_taxpayer_id' => $supplier->id, 'supplier_name' => trim($payload['supplier']['name']), 'supplier_vat_number' => $supplierVat,
                    'customer_taxpayer_id' => $customer->id ?? null, 'customer_name' => trim($payload['customer']['name']), 'customer_vat_number' => $customerVat,
                    'issue_date' => $payload['issue_date'], 'currency' => $payload['currency'],
                    'line_net_cents' => $calculated['lineNetCents'], 'tax_cents' => $calculated['taxCents'], 'total_cents' => $calculated['totalCents'],
                    'status' => $status, 'risk_level' => $risk['level'], 'payload_hash' => $requestHash,
                    'transaction_id' => $transactionId, 'certificate_id' => $certificateId, 'verification_token' => $verificationToken,
                    'created_at' => $now, 'certified_at' => $now,
                ]);

                if ($originalInvoice) {
                    $reference = $payload['original_document_reference'];
                    InvoiceCorrection::create([
                        'id' => (string) Str::uuid(), 'original_invoice_id' => $originalInvoice->id, 'correction_invoice_id' => $invoiceId,
                        'correction_type' => $documentType, 'reason_code' => $reference['reason_code'] ?? null,
                        'reason' => trim($reference['reason']), 'status' => 'ACTIVE', 'created_by' => $actor->id, 'created_at' => $now,
                    ]);
                }

                foreach ($calculated['lines'] as $line) {
                    InvoiceLine::create([
                        'id' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'line_number' => $line['line_number'],
                        'description' => trim($line['description']), 'quantity' => $line['quantity'], 'unit_code' => $line['unit_code'],
                        'unit_price_cents' => $line['unitPriceCents'], 'net_amount_cents' => $line['netAmountCents'],
                        'tax_rate_bps' => $line['taxRateBps'], 'tax_category' => $line['tax']['category'],
                        'tax_amount_cents' => $line['taxAmountCents'], 'vat_rule_id' => $vatRuleIdByLineNumber[$line['line_number']] ?? null,
                    ]);
                }

                Certificate::create([
                    'id' => $certificateId, 'invoice_id' => $invoiceId, 'verification_token' => $verificationToken,
                    'invoice_hash' => $requestHash, 'signature' => $signature, 'signature_profile' => 'DEV-SHA256',
                    'status' => 'VALID', 'issued_at' => $now,
                ]);

                // Module 2 Phase D PostTransaction: transactionId already groups this
                // submission's ledger_entries; this row formalizes it as its own record
                // and -- for a correction -- links back to the original's transaction so
                // a future GetTransactionTimeline port can walk the full lineage.
                VatTransaction::create([
                    'id' => $transactionId, 'invoice_id' => $invoiceId, 'taxpayer_id' => $supplier->id,
                    'transaction_type' => $originalInvoice ? 'CORRECTION' : 'CERTIFICATION',
                    'reference_transaction_id' => $originalInvoice->transaction_id ?? null,
                    'created_at' => $now,
                ]);

                $reversesVat = $documentType === 'CREDIT_NOTE';
                $ledgerVatCents = abs($calculated['taxCents']);
                LedgerEntry::create([
                    'id' => (string) Str::uuid(), 'transaction_id' => $transactionId, 'invoice_id' => $invoiceId,
                    'taxpayer_id' => $supplier->id, 'entry_type' => 'OUTPUT_VAT',
                    'direction' => $reversesVat ? 'DEBIT' : 'CREDIT', 'amount_cents' => $ledgerVatCents,
                    'period' => $period, 'created_at' => $now,
                ]);
                if ($customer) {
                    LedgerEntry::create([
                        'id' => (string) Str::uuid(), 'transaction_id' => $transactionId, 'invoice_id' => $invoiceId,
                        'taxpayer_id' => $customer->id, 'entry_type' => 'INPUT_VAT',
                        'direction' => $reversesVat ? 'CREDIT' : 'DEBIT', 'amount_cents' => $ledgerVatCents,
                        'period' => $period, 'created_at' => $now,
                    ]);
                }

                $exceptionId = null;
                if (count($risk['reasons']) > 0) {
                    $exceptionId = (string) Str::uuid();
                    ReconciliationException::create([
                        'id' => $exceptionId, 'invoice_id' => $invoiceId, 'taxpayer_id' => $supplier->id,
                        'exception_type' => $customer ? 'RISK_REVIEW' : 'UNREGISTERED_BUYER',
                        'severity' => $risk['level'] === 'LOW' ? 'MEDIUM' : $risk['level'],
                        'status' => 'OPEN', 'summary' => implode(' ', $risk['reasons']), 'created_at' => $now,
                    ]);
                }

                AuditService::append($actor, $originalInvoice ? 'INVOICE_CORRECTION_CERTIFIED' : 'INVOICE_CERTIFIED', 'INVOICE', $invoiceId, [
                    'invoiceNumber' => $payload['invoice_number'], 'transactionId' => $transactionId, 'certificateId' => $certificateId,
                    'riskLevel' => $risk['level'], 'exceptionId' => $exceptionId,
                    'correlationId' => $context['correlation_id'] ?? null, 'deviceId' => $context['device_id'] ?? null,
                    'sourceToken' => $context['source_token'] ?? null,
                ], $now);

                IdempotencyRecord::create([
                    'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash, 'response_invoice_id' => $invoiceId, 'created_at' => $now,
                ]);

                OutboxEvent::create([
                    'id' => (string) Str::uuid(), 'aggregate_type' => 'INVOICE', 'aggregate_id' => $invoiceId,
                    'event_type' => $originalInvoice ? 'InvoiceCorrected' : 'InvoiceCertified', 'event_version' => 1,
                    'partition_key' => $supplier->id,
                    'payload' => AuditService::canonicalJson(array_merge(
                        ['invoice_id' => $invoiceId, 'transaction_id' => $transactionId, 'certificate_id' => $certificateId],
                        $originalInvoice ? ['original_invoice_id' => $originalInvoice->id, 'correction_type' => $documentType] : [],
                        ['correlation_id' => $context['correlation_id'] ?? null],
                    )),
                    'status' => 'PENDING', 'publish_attempts' => 0, 'occurred_at' => $now, 'available_at' => $now,
                ]);

                if (in_array($risk['level'], ['HIGH', 'CRITICAL'], true)) {
                    SecurityEvent::create([
                        'id' => (string) Str::uuid(), 'event_type' => 'HIGH_RISK_TRANSACTION', 'severity' => $risk['level'],
                        'actor_id' => $actor->id, 'source_token' => $context['source_token'] ?? 'unknown',
                        'correlation_id' => $context['correlation_id'] ?? (string) Str::uuid(),
                        'action' => 'INVOICE_SUBMISSION', 'outcome' => 'FLAGGED',
                        'details' => AuditService::canonicalJson(['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'taxpayerId' => $supplier->id, 'riskReasons' => count($risk['reasons'])]),
                        'occurred_at' => $now,
                    ]);
                }
            });
        } catch (QueryException $e) {
            // Module 2 Phase E: idempotency under concurrent retries. The lookup-then-write
            // check above is not itself atomic -- two identical requests in flight together
            // can both pass it and both reach this transaction. Whichever commits second hits
            // a UNIQUE constraint (idempotency_records, or the invoices table's duplicate-
            // source-document/invoice-number guard, whichever statement runs first) and would
            // otherwise surface as a raw 500. Recover it into the same idempotent response the
            // earlier, non-racing case already returns, rather than letting it leak out.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            $race = IdempotencyRecord::where('actor_id', $actor->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($race) {
                if ($race->request_hash !== $requestHash) {
                    throw new RepositoryConflictException('The idempotency key was already used for a different invoice payload.');
                }
                $existing = $this->find($race->response_invoice_id, $actor);
                if ($existing) {
                    return $existing;
                }
            }
            throw new RepositoryConflictException('This invoice conflicts with one submitted concurrently for the same source document or invoice number.');
        }

        $result = $this->find($invoiceId, $actor);
        if (! $result) {
            throw new \RuntimeException('Invoice was committed but could not be reloaded.');
        }

        return $result;
    }

    /**
     * Minimal, standalone port of explainInvoiceVat's rule-listing (not its
     * full computation/timeline) -- an invoice's certified lines' resolved
     * VAT rules, deduplicated by rule id. Called uniformly after submit(),
     * whether it took the fresh-certification or idempotent-replay path,
     * matching route.ts's POST handler calling explainInvoiceVat separately
     * regardless of which path submitInvoice itself took.
     *
     * @return list<array{tax_category: string, vat_rule_id: string, vat_rule_version: int}>
     */
    public function vatRulesApplied(string $invoiceId): array
    {
        return InvoiceLine::where('invoice_lines.invoice_id', $invoiceId)
            ->whereNotNull('invoice_lines.vat_rule_id')
            ->join('vat_rules', 'vat_rules.id', '=', 'invoice_lines.vat_rule_id')
            ->select('invoice_lines.tax_category', 'vat_rules.id as vat_rule_id', 'vat_rules.version as vat_rule_version')
            ->get()
            ->unique('vat_rule_id')
            ->map(fn ($row) => ['tax_category' => $row->tax_category, 'vat_rule_id' => $row->vat_rule_id, 'vat_rule_version' => (int) $row->vat_rule_version])
            ->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function list(User $actor, int $limit = 100): array
    {
        $query = Invoice::query();
        if (! TenantScope::isNational($actor)) {
            $taxpayerId = $actor->taxpayer_id ?? '__none__';
            $query->where(function ($q) use ($taxpayerId) {
                $q->where('supplier_taxpayer_id', $taxpayerId)->orWhere('customer_taxpayer_id', $taxpayerId);
            });
        }

        return $query->orderByDesc('issue_date')->orderByDesc('certified_at')->limit($limit)->get()
            ->map(fn (Invoice $invoice) => $this->mapSummary($invoice))
            ->values()->all();
    }

    /** @return ?array<string, mixed> */
    public function find(string $id, User $actor): ?array
    {
        $query = Invoice::query()->with('certificate');
        if (! TenantScope::isNational($actor)) {
            $taxpayerId = $actor->taxpayer_id ?? '__none__';
            $query->where(function ($q) use ($taxpayerId) {
                $q->where('supplier_taxpayer_id', $taxpayerId)->orWhere('customer_taxpayer_id', $taxpayerId);
            });
        }
        $invoice = $query->find($id);
        if (! $invoice || ! $invoice->certificate) {
            return null;
        }

        $lines = InvoiceLine::where('invoice_id', $id)->orderBy('line_number')->get();
        $ledger = LedgerEntry::where('invoice_id', $id)
            ->join('taxpayers', 'taxpayers.id', '=', 'ledger_entries.taxpayer_id')
            ->orderByDesc('entry_type')
            ->select('ledger_entries.*', 'taxpayers.legal_name as taxpayer_name')
            ->get();
        $correction = InvoiceCorrection::where('correction_invoice_id', $id)
            ->join('invoices as originals', 'originals.id', '=', 'invoice_corrections.original_invoice_id')
            ->select('invoice_corrections.*', 'originals.invoice_number as original_invoice_number')
            ->first();
        $corrections = InvoiceCorrection::where('original_invoice_id', $id)
            ->join('invoices as corrected', 'corrected.id', '=', 'invoice_corrections.correction_invoice_id')
            ->select('invoice_corrections.*', 'corrected.invoice_number as correction_invoice_number', 'corrected.total_cents')
            ->orderBy('invoice_corrections.created_at')
            ->get();

        return array_merge($this->mapSummary($invoice), [
            'sourceSystem' => $invoice->source_system,
            'sourceDocumentId' => $invoice->source_document_id,
            'payloadHash' => $invoice->payload_hash,
            'signature' => $invoice->certificate->signature ?? '',
            'signatureProfile' => $invoice->certificate->signature_profile ?? '',
            'correction' => $correction ? [
                'originalInvoiceId' => $correction->original_invoice_id,
                'originalInvoiceNumber' => $correction->original_invoice_number,
                'correctionType' => $correction->correction_type,
                'reasonCode' => $correction->reason_code,
                'reason' => $correction->reason,
                'status' => $correction->status,
                'createdAt' => optional($correction->created_at)->toISOString(),
            ] : null,
            'corrections' => $corrections->map(fn ($c) => [
                'correctionInvoiceId' => $c->correction_invoice_id,
                'correctionInvoiceNumber' => $c->correction_invoice_number,
                'correctionType' => $c->correction_type,
                'reasonCode' => $c->reason_code,
                'reason' => $c->reason,
                'status' => $c->status,
                'totalCents' => (int) $c->total_cents,
                'createdAt' => optional($c->created_at)->toISOString(),
            ])->values()->all(),
            'lines' => $lines->map(fn ($l) => [
                'id' => $l->id, 'lineNumber' => $l->line_number, 'description' => $l->description, 'quantity' => $l->quantity,
                'unitCode' => $l->unit_code, 'unitPriceCents' => (int) $l->unit_price_cents, 'netAmountCents' => (int) $l->net_amount_cents,
                'taxRateBps' => (int) $l->tax_rate_bps, 'taxCategory' => $l->tax_category, 'taxAmountCents' => (int) $l->tax_amount_cents,
            ])->values()->all(),
            'ledgerEntries' => $ledger->map(fn ($e) => [
                'id' => $e->id, 'taxpayerName' => $e->taxpayer_name, 'entryType' => $e->entry_type,
                'direction' => $e->direction, 'amountCents' => (int) $e->amount_cents, 'period' => $e->period,
            ])->values()->all(),
        ]);
    }

    /**
     * Ported from lib/data/repository.ts's cancelInvoice (Module 2 Phase B).
     * Deliberately narrow and officer-only (invoices:cancel, step-up
     * gated -- see the route's own 'password.confirm' middleware): only a
     * TAX_INVOICE/SIMPLIFIED_TAX_INVOICE/SELF_BILLED_INVOICE with no active
     * correction against it can be cancelled. Never deletes or mutates the
     * original row; reverses its ledger effect the same way a credit note
     * does (new flipped-direction rows, never mutating existing ones) and
     * marks status='CANCELLED'. Idempotent on an already-cancelled invoice.
     * No idempotency-key handling here -- the source itself has none for
     * this command (unlike submit(), which uses idempotency_records).
     *
     * @return array{invoiceId: string, status: string}
     */
    public function cancel(User $actor, string $invoiceId, array $payload, string $correlationId): array
    {
        $reason = $this->normalizeCancellationReason($payload);
        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            throw new InvoiceValidationException([
                ['code' => 'INVOICE_NOT_FOUND', 'path' => '/invoice_id', 'message' => 'The invoice does not exist.'],
            ]);
        }
        TenantScope::requireTaxpayer($actor, $invoice->supplier_taxpayer_id);
        if ($invoice->status === 'CANCELLED') {
            return ['invoiceId' => $invoice->id, 'status' => 'CANCELLED'];
        }
        if (! in_array($invoice->document_type, ['TAX_INVOICE', 'SIMPLIFIED_TAX_INVOICE', 'SELF_BILLED_INVOICE'], true)) {
            throw new InvoiceValidationException([
                ['code' => 'NOT_CANCELLABLE_DOCUMENT_TYPE', 'path' => '/document_type', 'message' => 'Only an original tax invoice can be cancelled; a credit or debit note cannot.'],
            ]);
        }
        $activeCorrection = InvoiceCorrection::where('original_invoice_id', $invoice->id)->where('status', 'ACTIVE')->exists();
        if ($activeCorrection) {
            throw new RepositoryConflictException('This invoice already has an active credit or debit note against it; resolve the correction lineage instead of cancelling.');
        }

        $now = now();
        $transactionId = (string) Str::uuid();
        $period = mb_substr($invoice->issue_date->toDateString(), 0, 7);

        DB::transaction(function () use ($invoice, $actor, $now, $transactionId, $period, $reason, $correlationId) {
            Invoice::where('id', $invoice->id)->update(['status' => 'CANCELLED']);
            // Module 2 Phase D: Reverse. A new VATTransaction linked back to the
            // certification it reverses via reference_transaction_id -- the
            // certification row itself is never mutated.
            VatTransaction::create([
                'id' => $transactionId, 'invoice_id' => $invoice->id, 'taxpayer_id' => $invoice->supplier_taxpayer_id,
                'transaction_type' => 'CANCELLATION', 'reference_transaction_id' => $invoice->transaction_id, 'created_at' => $now,
            ]);
            LedgerEntry::create([
                'id' => (string) Str::uuid(), 'transaction_id' => $transactionId, 'invoice_id' => $invoice->id,
                'taxpayer_id' => $invoice->supplier_taxpayer_id, 'entry_type' => 'OUTPUT_VAT', 'direction' => 'DEBIT',
                'amount_cents' => $invoice->tax_cents, 'period' => $period, 'created_at' => $now,
            ]);
            if ($invoice->customer_taxpayer_id) {
                LedgerEntry::create([
                    'id' => (string) Str::uuid(), 'transaction_id' => $transactionId, 'invoice_id' => $invoice->id,
                    'taxpayer_id' => $invoice->customer_taxpayer_id, 'entry_type' => 'INPUT_VAT', 'direction' => 'CREDIT',
                    'amount_cents' => $invoice->tax_cents, 'period' => $period, 'created_at' => $now,
                ]);
            }
            // The source reimplements a raw audit_events insert here, ordering
            // its prior-hash lookup by occurred_at DESC rather than true
            // insertion order -- the same caller-supplied-timestamp race
            // SECURITY_GAP_ASSESSMENT.md item #9 flags elsewhere in this
            // codebase. Using the canonical AuditService::append writer here
            // instead (as every other command in this migration does) avoids
            // reintroducing that race rather than faithfully reproducing it.
            AuditService::append($actor, 'INVOICE_CANCELLED', 'INVOICE', $invoice->id, ['invoiceId' => $invoice->id, 'reason' => $reason, 'correlationId' => $correlationId], $now);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'INVOICE', 'aggregate_id' => $invoice->id,
                'event_type' => 'InvoiceCancelled', 'event_version' => 1, 'partition_key' => $invoice->supplier_taxpayer_id,
                'payload' => AuditService::canonicalJson(['invoice_id' => $invoice->id, 'reason' => $reason, 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'publish_attempts' => 0, 'occurred_at' => $now, 'available_at' => $now,
            ]);
        });

        return ['invoiceId' => $invoice->id, 'status' => 'CANCELLED'];
    }

    /**
     * Ported from lib/data/repository.ts's explainInvoiceVat (Module 2 Phase
     * A ExplainCalculation): for an already-certified invoice, exactly which
     * approved VATRule version produced each line's tax amount. vat_rule_id
     * is stored on invoice_lines at submission time (submit() above); this
     * just projects it back out, tenant-scoped the same way find() is (a
     * supplier or a customer may both read it).
     *
     * @return ?array<string, mixed>
     */
    public function explainVat(string $id, User $actor): ?array
    {
        $query = Invoice::query();
        if (! TenantScope::isNational($actor)) {
            $taxpayerId = $actor->taxpayer_id ?? '__none__';
            $query->where(fn ($q) => $q->where('supplier_taxpayer_id', $taxpayerId)->orWhere('customer_taxpayer_id', $taxpayerId));
        }
        $invoice = $query->find($id, ['id', 'invoice_number']);
        if (! $invoice) {
            return null;
        }

        $lines = InvoiceLine::where('invoice_lines.invoice_id', $id)
            ->leftJoin('vat_rules', 'vat_rules.id', '=', 'invoice_lines.vat_rule_id')
            ->orderBy('invoice_lines.line_number')
            ->select([
                'invoice_lines.line_number', 'invoice_lines.tax_category', 'invoice_lines.net_amount_cents',
                'invoice_lines.tax_rate_bps', 'invoice_lines.tax_amount_cents',
                'vat_rules.id as vat_rule_id', 'vat_rules.version as vat_rule_version',
                'vat_rules.effective_from as rule_effective_from', 'vat_rules.effective_to as rule_effective_to',
            ])->get();

        return [
            'invoiceId' => $invoice->id, 'invoiceNumber' => $invoice->invoice_number,
            'lines' => $lines->map(fn ($line) => [
                'lineNumber' => (int) $line->line_number, 'taxCategory' => $line->tax_category,
                'taxableAmountCents' => (int) $line->net_amount_cents, 'taxRateBps' => (int) $line->tax_rate_bps,
                'taxAmountCents' => (int) $line->tax_amount_cents, 'vatRuleId' => $line->vat_rule_id,
                'vatRuleVersion' => $line->vat_rule_version !== null ? (int) $line->vat_rule_version : null,
                'ruleEffectiveFrom' => $line->rule_effective_from, 'ruleEffectiveTo' => $line->rule_effective_to,
            ])->values()->all(),
        ];
    }

    /**
     * Ported from lib/data/repository.ts's getTransactionTimeline (Module 2
     * Phase D): the complete audit narrative for one invoice's lineage --
     * its certification, every correction issued against it, and its
     * cancellation if any -- as a chronological sequence of VATTransaction
     * events, each with the ledger postings it actually made. Accepts any
     * invoice id within a lineage (the true original, or one of its
     * corrections) and always resolves to the same timeline, rooted at the
     * original. Tenant scope is checked once, against the invoice the
     * caller asked for; that transitively secures the rest of the lineage,
     * since a correction's supplier and customer are invariant with its
     * original by construction (submit() enforces both).
     *
     * @return ?array<string, mixed>
     */
    public function transactionTimeline(string $id, User $actor): ?array
    {
        $query = Invoice::query();
        if (! TenantScope::isNational($actor)) {
            $taxpayerId = $actor->taxpayer_id ?? '__none__';
            $query->where(fn ($q) => $q->where('supplier_taxpayer_id', $taxpayerId)->orWhere('customer_taxpayer_id', $taxpayerId));
        }
        $invoice = $query->find($id, ['id', 'invoice_number']);
        if (! $invoice) {
            return null;
        }

        $asCorrectionOf = InvoiceCorrection::where('correction_invoice_id', $invoice->id)->value('original_invoice_id');
        $rootId = $asCorrectionOf ?? $invoice->id;
        $root = $rootId === $invoice->id ? $invoice : Invoice::find($rootId, ['id', 'invoice_number']);
        if (! $root) {
            return null;
        }

        $correctionIds = InvoiceCorrection::where('original_invoice_id', $rootId)->orderBy('created_at')->pluck('correction_invoice_id')->all();
        $lineageInvoiceIds = [$rootId, ...$correctionIds];

        $transactions = VatTransaction::whereIn('vat_transactions.invoice_id', $lineageInvoiceIds)
            ->join('invoices', 'invoices.id', '=', 'vat_transactions.invoice_id')
            ->orderBy('vat_transactions.created_at')
            ->select([
                'vat_transactions.id', 'vat_transactions.transaction_type', 'vat_transactions.reference_transaction_id',
                'vat_transactions.invoice_id', 'vat_transactions.created_at',
                'invoices.invoice_number', 'invoices.document_type',
            ])->get();

        $ledgerByTransaction = LedgerEntry::whereIn('ledger_entries.transaction_id', $transactions->pluck('id')->all())
            ->join('taxpayers', 'taxpayers.id', '=', 'ledger_entries.taxpayer_id')
            ->orderByDesc('ledger_entries.entry_type')
            ->select(['ledger_entries.transaction_id', 'ledger_entries.entry_type', 'ledger_entries.direction', 'ledger_entries.amount_cents', 'ledger_entries.period', 'taxpayers.legal_name as taxpayer_name'])
            ->get()->groupBy('transaction_id');

        return [
            'rootInvoiceId' => $root->id, 'rootInvoiceNumber' => $root->invoice_number,
            'events' => $transactions->map(fn ($transaction) => [
                'transactionId' => $transaction->id, 'transactionType' => $transaction->transaction_type,
                'referenceTransactionId' => $transaction->reference_transaction_id, 'invoiceId' => $transaction->invoice_id,
                'invoiceNumber' => $transaction->invoice_number, 'documentType' => $transaction->document_type,
                'occurredAt' => optional($transaction->created_at)->toISOString(),
                'ledgerEntries' => ($ledgerByTransaction->get($transaction->id) ?? collect())->map(fn ($entry) => [
                    'taxpayerName' => $entry->taxpayer_name, 'entryType' => $entry->entry_type,
                    'direction' => $entry->direction, 'amountCents' => (int) $entry->amount_cents, 'period' => $entry->period,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function normalizeCancellationReason(array $payload): string
    {
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim(preg_replace('/\s+/', ' ', $payload['reason'])) : '';
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 240) {
            throw new InvoiceValidationException([
                ['code' => 'REASON_INVALID', 'path' => '/reason', 'message' => 'Provide a 10 to 240 character cancellation reason.'],
            ]);
        }

        return $reason;
    }

    /** @return array<string, mixed> */
    private function mapSummary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id, 'invoiceNumber' => $invoice->invoice_number, 'documentType' => $invoice->document_type,
            'supplierName' => $invoice->supplier_name, 'supplierVatNumber' => $invoice->supplier_vat_number,
            'customerName' => $invoice->customer_name, 'customerVatNumber' => $invoice->customer_vat_number,
            'issueDate' => $invoice->issue_date->toDateString(), 'currency' => $invoice->currency,
            'lineNetCents' => (int) $invoice->line_net_cents, 'taxCents' => (int) $invoice->tax_cents, 'totalCents' => (int) $invoice->total_cents,
            'status' => $invoice->status, 'riskLevel' => $invoice->risk_level, 'transactionId' => $invoice->transaction_id,
            'certificateId' => $invoice->certificate_id, 'verificationToken' => $invoice->verification_token,
            'certifiedAt' => optional($invoice->certified_at)->toISOString(),
        ];
    }

    /** Ported from lib/data/vat-rule-repository.ts's getApplicableVatRule -- fails closed, never assumes a default rate. Delegates to VatRuleResolver, single-sourced with VatRuleService::evaluate rather than duplicated (see that class's own doc comment). */
    private function applicableVatRule(string $taxCategory, string $isoDate): ?VatRule
    {
        return VatRuleResolver::applicable($taxCategory, $isoDate);
    }

    /** Ported from submitInvoice's inline supplier/customer resolution query -- the dynamic BUYER/SELLER capability grant, never a static role. */
    private function resolveCapableTaxpayer(string $vatNumber, string $capability, Carbon $now): ?Taxpayer
    {
        return Taxpayer::query()
            ->join('organisations', function ($join) {
                $join->on('organisations.taxpayer_id', '=', 'taxpayers.id')->where('organisations.status', 'ACTIVE');
            })
            ->join('organisation_capabilities', function ($join) use ($capability, $now) {
                $join->on('organisation_capabilities.organisation_id', '=', 'organisations.id')
                    ->where('organisation_capabilities.capability', $capability)
                    ->where('organisation_capabilities.status', 'ACTIVE')
                    ->where('organisation_capabilities.effective_from', '<=', $now)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('organisation_capabilities.effective_to')->orWhere('organisation_capabilities.effective_to', '>', $now);
                    });
            })
            ->where('taxpayers.vat_number', $vatNumber)
            ->where('taxpayers.vat_status', 'ACTIVE')
            ->select('taxpayers.*')
            ->first();
    }

    /** Ported from submitInvoice's correction-resolution block (credit/debit note lineage + cumulative-credit cap). */
    private function resolveOriginalInvoice(array $payload, Taxpayer $supplier, ?Taxpayer $customer, ?string $customerVat, array $calculated): Invoice
    {
        $reference = $payload['original_document_reference'];
        if (! empty($reference['vat_msa_invoice_id'])) {
            $originalInvoice = Invoice::where('id', $reference['vat_msa_invoice_id'])->where('supplier_taxpayer_id', $supplier->id)->first();
            if ($originalInvoice && $originalInvoice->source_document_id !== $reference['source_document_id']) {
                throw new RepositoryConflictException("The correction's VAT-MSA invoice id and source document reference do not identify the same original invoice.");
            }
        } else {
            $candidates = Invoice::where('source_document_id', $reference['source_document_id'])->where('supplier_taxpayer_id', $supplier->id)->limit(2)->get();
            if ($candidates->count() > 1) {
                throw new RepositoryConflictException('The source document reference is ambiguous; include vat_msa_invoice_id.');
            }
            $originalInvoice = $candidates->first();
        }
        if (! $originalInvoice) {
            throw new RepositoryConflictException('The original invoice was not found in the authorised supplier scope.');
        }
        if (! in_array($originalInvoice->document_type, ['TAX_INVOICE', 'SIMPLIFIED_TAX_INVOICE', 'SELF_BILLED_INVOICE'], true)) {
            throw new RepositoryConflictException('A correction must reference an original invoice, not another correction document.');
        }
        if ($originalInvoice->currency !== $payload['currency']) {
            throw new RepositoryConflictException('A correction must use the original invoice currency.');
        }
        if ($payload['issue_date'] < $originalInvoice->issue_date->toDateString()) {
            throw new RepositoryConflictException('A correction cannot be issued before the original invoice.');
        }
        if ($originalInvoice->customer_taxpayer_id !== ($customer->id ?? null) || ($originalInvoice->customer_vat_number ?? null) !== $customerVat) {
            throw new RepositoryConflictException('A correction must preserve the original customer identity.');
        }
        if ($payload['document_type'] === 'CREDIT_NOTE') {
            $prior = InvoiceCorrection::where('invoice_corrections.original_invoice_id', $originalInvoice->id)
                ->where('invoice_corrections.correction_type', 'CREDIT_NOTE')->where('invoice_corrections.status', 'ACTIVE')
                ->join('invoices', 'invoices.id', '=', 'invoice_corrections.correction_invoice_id')
                ->selectRaw('COALESCE(SUM(invoices.line_net_cents),0) as line_net_cents, COALESCE(SUM(invoices.tax_cents),0) as tax_cents, COALESCE(SUM(invoices.total_cents),0) as total_cents')
                ->first();
            $cumulativeLine = (int) ($prior->line_net_cents ?? 0) + $calculated['lineNetCents'];
            $cumulativeTax = (int) ($prior->tax_cents ?? 0) + $calculated['taxCents'];
            $cumulativeTotal = (int) ($prior->total_cents ?? 0) + $calculated['totalCents'];
            if (abs($cumulativeLine) > $originalInvoice->line_net_cents || abs($cumulativeTax) > $originalInvoice->tax_cents || abs($cumulativeTotal) > $originalInvoice->total_cents) {
                throw new RepositoryConflictException('The cumulative credit would exceed the original invoice value or VAT.');
            }
        }

        return $originalInvoice;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
