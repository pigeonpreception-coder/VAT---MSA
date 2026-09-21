<?php

namespace App\Services\Reconciliation;

use App\Domain\Reconciliation\ReconciliationValidator;
use App\Exceptions\ReconciliationValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\ReconciliationException;
use App\Models\ReconciliationMatch;
use App\Models\User;
use App\Models\VatPeriod;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/reconciliation-repository.ts -- Module 3 Phase A/B:
 * the reconciliation matching engine and its NamRA-officer work queue.
 * `reconciliation_matches`/`reconciliation_exceptions` were already
 * schema-ported (each migration's own comment named this exact module as
 * "a separate, still-unmigrated module... tracked as a further gap, not
 * silently dropped") -- this is that gap, closed.
 */
class ReconciliationService
{
    /**
     * RunMatch: an independent verification pass for one invoice,
     * re-deriving what its ledger postings *should* be from the invoice's
     * own declared figures and status, and comparing against what was
     * actually posted. Deliberately invoice-scoped rather than a
     * period-wide sweep or a scheduled job -- source itself has no cron
     * wired up either, leaving "scheduled/event-driven" a documented gap
     * rather than faking it; this is the correct per-invoice building
     * block such a job would call. Idempotent two ways: a retry against an
     * already-matched invoice returns the existing match via
     * reconciliation_matches' own UNIQUE(invoice_id, taxpayer_id)
     * constraint regardless of idempotency key, in front of which the
     * standard replay check still runs (reusing the same key for two
     * genuinely different invoices must 409 cleanly, not hit that unique
     * constraint as a raw DB error).
     *
     * @return array<string, mixed>
     */
    public function runMatch(string $invoiceId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $requestHash = CommandLedger::requestHash(['invoice_id' => $invoiceId]);
        $prior = CommandLedger::prior($actor->id, 'RUN_MATCH', $idempotencyKey, $requestHash);
        if ($prior) {
            $priorMatch = ReconciliationMatch::find($prior);
            if ($priorMatch) {
                return $this->presentMatch($priorMatch);
            }
        }

        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            throw new ReconciliationValidationException('INVOICE_NOT_FOUND', 'The invoice does not exist.');
        }
        TenantScope::requireTaxpayer($actor, $invoice->supplier_taxpayer_id);

        $existing = ReconciliationMatch::where('invoice_id', $invoice->id)->where('taxpayer_id', $invoice->supplier_taxpayer_id)->first();
        if ($existing) {
            return $this->presentMatch($existing);
        }

        $ownOutput = DB::table('ledger_entries')->where('transaction_id', $invoice->transaction_id)->where('entry_type', 'OUTPUT_VAT')->value('amount_cents');
        $ownInput = DB::table('ledger_entries')->where('transaction_id', $invoice->transaction_id)->where('entry_type', 'INPUT_VAT')->value('amount_cents');
        $cancellationOutput = $invoice->status === 'CANCELLED'
            ? DB::table('vat_transactions as t')->join('ledger_entries as l', 'l.transaction_id', '=', 't.id')
                ->where('t.reference_transaction_id', $invoice->transaction_id)->where('t.transaction_type', 'CANCELLATION')
                ->where('l.entry_type', 'OUTPUT_VAT')->value('l.amount_cents')
            : null;

        $result = ReconciliationValidator::evaluateInvoiceMatch(
            (int) $invoice->tax_cents,
            $ownOutput !== null ? (int) $ownOutput : null,
            $invoice->customer_taxpayer_id !== null,
            $ownInput !== null ? (int) $ownInput : null,
            $invoice->status === 'CANCELLED',
            $cancellationOutput !== null ? (int) $cancellationOutput : null,
        );

        $organisation = Organisation::where('taxpayer_id', $invoice->supplier_taxpayer_id)->first();
        if (! $organisation) {
            throw new ReconciliationValidationException('ORGANISATION_NOT_FOUND', "The supplier's organisation could not be resolved.");
        }
        $period = mb_substr((string) $invoice->issue_date, 0, 7);
        $vatPeriod = VatPeriod::where('taxpayer_id', $invoice->supplier_taxpayer_id)->where('period_code', $period)->first();

        $now = now();
        $matchId = (string) Str::uuid();
        $evidence = AuditService::canonicalJson(['invoiceId' => $invoice->id, 'invoiceTaxCents' => $invoice->tax_cents, 'mismatches' => $result['mismatches']]);

        DB::transaction(function () use ($matchId, $organisation, $invoice, $vatPeriod, $result, $evidence, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            ReconciliationMatch::create([
                'id' => $matchId, 'organisation_id' => $organisation->id, 'taxpayer_id' => $invoice->supplier_taxpayer_id,
                'vat_period_id' => $vatPeriod?->id, 'invoice_id' => $invoice->id, 'ledger_entry_id' => null,
                'match_type' => 'LEDGER_CONSISTENCY', 'confidence_bps' => $result['status'] === 'MATCHED' ? 10_000 : 0,
                'status' => $result['status'], 'evidence' => $evidence, 'reconciled_by' => $actor->id,
                'reconciled_at' => $now, 'created_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'RUN_MATCH', $idempotencyKey, $requestHash, 'RECONCILIATION_MATCH', $matchId, $now);
            CommandLedger::outbox('RECONCILIATION_MATCH', $matchId, $result['status'] === 'MATCHED' ? 'VATTransactionMatched' : 'ExceptionDetected', $invoice->supplier_taxpayer_id, [
                'match_id' => $matchId, 'invoice_id' => $invoice->id, 'status' => $result['status'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'RECONCILIATION_MATCH_RUN', 'RECONCILIATION_MATCH', $matchId, [
                'invoiceId' => $invoice->id, 'status' => $result['status'], 'mismatches' => $result['mismatches'],
            ], $now);
            if ($result['status'] === 'EXCEPTION') {
                ReconciliationException::create([
                    'id' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'taxpayer_id' => $invoice->supplier_taxpayer_id,
                    'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN',
                    'summary' => implode(' ', $result['mismatches']), 'created_at' => $now, 'resolved_at' => null,
                    'assigned_officer_id' => null, 'resolved_by' => null, 'resolution_notes' => null,
                ]);
            }
        });

        return $this->presentMatch(ReconciliationMatch::find($matchId));
    }

    /**
     * Assign: hands a reconciliation exception to a specific officer. Not
     * itself the work queue -- see getWorkQueue() -- just the mutation a
     * queue's own "Assign" action calls.
     */
    public function assignException(string $exceptionId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ReconciliationValidator::exceptionAssignment($payload);
        $requestHash = CommandLedger::requestHash(['exception_id' => $exceptionId, 'officer_id' => $input['officer_id']]);
        $prior = CommandLedger::prior($actor->id, 'ASSIGN_EXCEPTION', $idempotencyKey, $requestHash);
        if ($prior) {
            return ['id' => $prior, 'status' => 'ASSIGNED'];
        }

        $exception = ReconciliationException::find($exceptionId);
        if (! $exception) {
            throw new ReconciliationValidationException('EXCEPTION_NOT_FOUND', 'The reconciliation exception does not exist.');
        }
        TenantScope::requireTaxpayer($actor, $exception->taxpayer_id ?? '');
        if ($exception->status === 'RESOLVED') {
            throw new RepositoryConflictException('This exception is already resolved and cannot be reassigned.');
        }
        $officer = User::find($input['officer_id']);
        if (! $officer) {
            throw new ReconciliationValidationException('OFFICER_NOT_FOUND', 'The officer does not exist.');
        }
        if ($officer->status !== 'ACTIVE') {
            throw new ReconciliationValidationException('OFFICER_NOT_ACTIVE', 'The officer is not active.');
        }

        $now = now();
        DB::transaction(function () use ($exceptionId, $exception, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            ReconciliationException::where('id', $exceptionId)->update(['status' => 'ASSIGNED', 'assigned_officer_id' => $input['officer_id']]);
            CommandLedger::record($actor->id, 'ASSIGN_EXCEPTION', $idempotencyKey, $requestHash, 'RECONCILIATION_EXCEPTION', $exceptionId, $now);
            CommandLedger::outbox('RECONCILIATION_EXCEPTION', $exceptionId, 'ExceptionAssigned', $exception->taxpayer_id ?? $exceptionId, [
                'exception_id' => $exceptionId, 'officer_id' => $input['officer_id'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'EXCEPTION_ASSIGNED', 'RECONCILIATION_EXCEPTION', $exceptionId, ['officerId' => $input['officer_id']], $now);
        });

        return ['id' => $exceptionId, 'status' => 'ASSIGNED'];
    }

    /** ResolveException. Idempotent on an already-resolved exception. */
    public function resolveException(string $exceptionId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ReconciliationValidator::exceptionResolution($payload);
        $requestHash = CommandLedger::requestHash(['exception_id' => $exceptionId, 'notes' => $input['notes']]);
        $prior = CommandLedger::prior($actor->id, 'RESOLVE_EXCEPTION', $idempotencyKey, $requestHash);
        if ($prior) {
            return ['id' => $prior, 'status' => 'RESOLVED'];
        }

        $exception = ReconciliationException::find($exceptionId);
        if (! $exception) {
            throw new ReconciliationValidationException('EXCEPTION_NOT_FOUND', 'The reconciliation exception does not exist.');
        }
        TenantScope::requireTaxpayer($actor, $exception->taxpayer_id ?? '');
        if ($exception->status === 'RESOLVED') {
            return ['id' => $exceptionId, 'status' => 'RESOLVED'];
        }

        $now = now();
        DB::transaction(function () use ($exceptionId, $exception, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            ReconciliationException::where('id', $exceptionId)->update([
                'status' => 'RESOLVED', 'resolved_at' => $now, 'resolved_by' => $actor->id, 'resolution_notes' => $input['notes'],
            ]);
            CommandLedger::record($actor->id, 'RESOLVE_EXCEPTION', $idempotencyKey, $requestHash, 'RECONCILIATION_EXCEPTION', $exceptionId, $now);
            CommandLedger::outbox('RECONCILIATION_EXCEPTION', $exceptionId, 'ExceptionResolved', $exception->taxpayer_id ?? $exceptionId, [
                'exception_id' => $exceptionId, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'EXCEPTION_RESOLVED', 'RECONCILIATION_EXCEPTION', $exceptionId, ['notes' => $input['notes']], $now);
        });

        return ['id' => $exceptionId, 'status' => 'RESOLVED'];
    }

    /**
     * GetWorkQueue: filter/status/officer/age predicates over
     * reconciliation_exceptions, tenant-scoped -- NamRA/national-scope
     * actors see every taxpayer's exceptions, everyone else only their
     * own (an invoice they supplied or bought against).
     *
     * @return array<string, mixed>
     */
    public function getWorkQueue(Request $request, User $actor): array
    {
        $query = ReconciliationValidator::workQueueQuery($request);

        $base = DB::table('reconciliation_exceptions as e')
            ->join('invoices as i', 'i.id', '=', 'e.invoice_id')
            ->leftJoin('users as officer', 'officer.id', '=', 'e.assigned_officer_id');
        $this->applyFilters($base, $query, $actor);

        $items = (clone $base)
            ->selectRaw('e.id, e.invoice_id, e.taxpayer_id, e.exception_type, e.severity, e.status, e.summary, '.
                'e.created_at, e.resolved_at, e.assigned_officer_id, officer.name as assigned_officer_name, '.
                'e.resolved_by, e.resolution_notes, i.invoice_number, i.supplier_name, i.total_cents, i.currency, '.
                'TIMESTAMPDIFF(DAY, e.created_at, NOW()) as age_days')
            ->orderByRaw("CASE e.severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END")
            ->orderByDesc('e.created_at')
            ->limit($query['limit'])->offset($query['offset'])->get();
        $totalCount = (clone $base)->count();

        return [
            'items' => $items->map(fn ($row) => [
                'id' => $row->id, 'invoice_id' => $row->invoice_id, 'taxpayer_id' => $row->taxpayer_id,
                'exception_type' => $row->exception_type, 'severity' => $row->severity, 'status' => $row->status,
                'summary' => $row->summary, 'created_at' => $row->created_at, 'resolved_at' => $row->resolved_at,
                'assigned_officer_id' => $row->assigned_officer_id, 'assigned_officer_name' => $row->assigned_officer_name,
                'resolved_by' => $row->resolved_by, 'resolution_notes' => $row->resolution_notes,
                'invoice_number' => $row->invoice_number, 'supplier_name' => $row->supplier_name,
                'total_cents' => $row->total_cents, 'currency' => $row->currency, 'age_days' => (int) $row->age_days,
            ])->all(),
            'total_count' => $totalCount, 'limit' => $query['limit'], 'offset' => $query['offset'],
        ];
    }

    private function applyFilters(Builder $q, array $query, User $actor): void
    {
        if (! TenantScope::isNational($actor)) {
            $taxpayerId = $actor->taxpayer_id ?? '__none__';
            $q->where(fn ($w) => $w->where('i.supplier_taxpayer_id', $taxpayerId)->orWhere('i.customer_taxpayer_id', $taxpayerId));
        }
        if ($query['status']) {
            $q->where('e.status', $query['status']);
        }
        if ($query['severity']) {
            $q->where('e.severity', $query['severity']);
        }
        if ($query['assigned_officer_id']) {
            $q->where('e.assigned_officer_id', $query['assigned_officer_id']);
        }
        if ($query['unassigned_only']) {
            $q->whereNull('e.assigned_officer_id');
        }
        if ($query['min_age_days'] !== null) {
            $q->whereRaw('TIMESTAMPDIFF(DAY, e.created_at, NOW()) >= ?', [$query['min_age_days']]);
        }
        if ($query['max_age_days'] !== null) {
            $q->whereRaw('TIMESTAMPDIFF(DAY, e.created_at, NOW()) <= ?', [$query['max_age_days']]);
        }
    }

    /** @return array<string, mixed> */
    private function presentMatch(?ReconciliationMatch $match): array
    {
        if (! $match) {
            throw new RepositoryConflictException('The idempotent reconciliation resource is no longer available.');
        }
        $evidence = json_decode($match->evidence, true) ?? [];

        return [
            'id' => $match->id, 'invoice_id' => $match->invoice_id, 'taxpayer_id' => $match->taxpayer_id,
            'status' => $match->status, 'match_type' => $match->match_type, 'confidence_bps' => $match->confidence_bps,
            'mismatches' => $evidence['mismatches'] ?? [], 'reconciled_at' => optional($match->reconciled_at)->toISOString(),
        ];
    }
}
