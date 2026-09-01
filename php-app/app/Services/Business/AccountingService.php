<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's postJournal/createAccount/
 * reverseJournalEntry/closeAccountingPeriod/getTrialBalance/
 * getFinancialStatements -- Module 5 Phase C, the second Phase 10 slice.
 * Deliberately a "lighter CRUD standard" per the source's own note: no full
 * general-ledger period-end closing cycle, see getFinancialStatements'
 * doc comment below.
 */
class AccountingService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function postJournal(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $journal = BusinessValidator::journal($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'journal' => $journal]);
        $prior = CommandLedger::prior($actor->id, 'POST_JOURNAL', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findEntryOrFail($prior, $organisation->id);
        }
        $this->assertPeriodOpen($organisation->id, $journal['journal_date']);
        foreach ($journal['lines'] as $line) {
            $this->requireOwnedAccount($line['account_id'], $organisation->id);
            $this->requireOwnedBranch($line['branch_id'], $organisation->id);
            // project_id: no ownership check yet -- projects (Phase 10's own later sub-slice)
            // has no table to check against; accepted as an opaque reference (see the
            // journal_lines migration's own doc comment).
        }

        $id = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($journal, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            JournalEntry::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'journal_number' => $journal['journal_number'],
                'journal_date' => $journal['journal_date'], 'reference' => $journal['reference'], 'description' => $journal['description'],
                'currency' => $journal['currency'], 'status' => 'POSTED', 'source_type' => $journal['source_type'],
                'source_id' => $journal['source_id'], 'created_by' => $actor->id, 'posted_by' => $actor->id,
                'created_at' => $now, 'posted_at' => $now,
            ]);
            $this->insertLines($id, $journal['lines']);
            CommandLedger::record($actor->id, 'POST_JOURNAL', $idempotencyKey, $requestHash, 'JOURNAL', $id, $now);
            CommandLedger::outbox('JOURNAL', $id, 'JournalPosted', $organisation->id, ['journal_id' => $id, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'JOURNAL_POSTED', 'JOURNAL', $id, ['organisationId' => $organisation->id, 'journalNumber' => $journal['journal_number'], 'correlationId' => $correlationId], $now);
        });

        return $this->findEntryOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function createAccount(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $account = BusinessValidator::account($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'account' => $account]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_ACCOUNT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentAccount($this->findAccountOrFail($prior, $organisation->id));
        }
        $existing = ChartOfAccount::where('organisation_id', $organisation->id)->where('code', $account['code'])->first();
        if ($existing) {
            throw new RepositoryConflictException("Account code {$account['code']} is already in use ({$existing->name}, {$existing->id}).");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($account, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            ChartOfAccount::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'code' => $account['code'], 'name' => $account['name'],
                'account_type' => $account['account_type'], 'currency' => $account['currency'], 'control_type' => $account['control_type'],
                'status' => 'ACTIVE', 'created_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_ACCOUNT', $idempotencyKey, $requestHash, 'ACCOUNT', $id, $now);
            CommandLedger::outbox('ACCOUNT', $id, 'AccountCreated', $organisation->id, ['account_id' => $id, 'organisation_id' => $organisation->id, 'code' => $account['code'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'ACCOUNT_CREATED', 'ACCOUNT', $id, ['organisationId' => $organisation->id, 'code' => $account['code'], 'accountType' => $account['account_type'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentAccount($this->findAccountOrFail($id, $organisation->id));
    }

    /**
     * A posted journal is never edited or deleted -- a reversal is a
     * brand-new, equal-and-opposite entry (every line's debit/credit
     * swapped) posted as of today, with the original flipped to
     * status='REVERSED' as a traceability marker only. Both entries remain
     * in journal_lines and both count toward the trial balance/statements
     * -- their opposite amounts net to zero naturally.
     *
     * @return array<string, mixed>
     */
    public function reverseJournalEntry(string $journalEntryId, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = BusinessValidator::journalReversal($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $original = JournalEntry::where('id', $journalEntryId)->where('organisation_id', $organisation->id)->first();
        if (! $original) {
            throw new BusinessResourceException('Journal entry was not found in the authorised organisation.', 404);
        }
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'journal_entry_id' => $journalEntryId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'REVERSE_JOURNAL_ENTRY', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findEntryOrFail($prior, $organisation->id);
        }
        if ($original->status !== 'POSTED') {
            throw new RepositoryConflictException("Only a posted journal entry can be reversed; {$journalEntryId} is currently {$original->status}.");
        }
        $alreadyReversed = JournalEntry::where('reverses_journal_entry_id', $journalEntryId)->first();
        if ($alreadyReversed) {
            throw new RepositoryConflictException("This journal entry was already reversed as {$alreadyReversed->id}.");
        }

        $now = now();
        $journalDate = $now->toDateString();
        $this->assertPeriodOpen($organisation->id, $journalDate);
        $originalLines = JournalLine::where('journal_entry_id', $journalEntryId)->orderBy('line_number')->get();

        $id = (string) Str::uuid();
        $journalNumber = "{$original->journal_number}-REV";

        DB::transaction(function () use ($original, $originalLines, $organisation, $actor, $id, $journalNumber, $journalDate, $journalEntryId, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
            JournalEntry::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'journal_number' => $journalNumber, 'journal_date' => $journalDate,
                'reference' => $original->journal_number, 'description' => "Reversal of {$original->journal_number}: {$input['reason']}",
                'currency' => $original->currency, 'status' => 'POSTED', 'source_type' => 'ADJUSTMENT', 'source_id' => $journalEntryId,
                'created_by' => $actor->id, 'posted_by' => $actor->id, 'created_at' => $now, 'posted_at' => $now,
                'reverses_journal_entry_id' => $journalEntryId,
            ]);
            JournalEntry::where('id', $journalEntryId)->update(['status' => 'REVERSED']);
            foreach ($originalLines as $index => $line) {
                JournalLine::create([
                    'id' => (string) Str::uuid(), 'journal_entry_id' => $id, 'line_number' => $index + 1,
                    'account_id' => $line->account_id, 'branch_id' => $line->branch_id, 'project_id' => $line->project_id,
                    'description' => "Reversal: {$line->description}", 'debit_cents' => $line->credit_cents,
                    'credit_cents' => $line->debit_cents, 'tax_code' => $line->tax_code,
                ]);
            }
            CommandLedger::record($actor->id, 'REVERSE_JOURNAL_ENTRY', $idempotencyKey, $requestHash, 'JOURNAL', $id, $now);
            CommandLedger::outbox('JOURNAL', $id, 'JournalReversed', $organisation->id, ['journal_id' => $id, 'reverses_journal_entry_id' => $journalEntryId, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'JOURNAL_REVERSED', 'JOURNAL', $id, ['organisationId' => $organisation->id, 'reversesJournalEntryId' => $journalEntryId, 'reason' => $input['reason'], 'correlationId' => $correlationId], $now);
        });

        return $this->findEntryOrFail($id, $organisation->id);
    }

    /**
     * Idempotent on an already-closed period -- re-closing is a no-op
     * success, not an error. A period only gains a row once it's actually
     * closed; there is no separate "open a period" command.
     *
     * @return array<string, mixed>
     */
    public function closePeriod(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = BusinessValidator::periodClose($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CLOSE_ACCOUNTING_PERIOD', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentPeriod($this->findPeriodOrFail($prior, $organisation->id));
        }

        [$year, $month] = array_map('intval', explode('-', $input['period_code']));
        $periodStart = "{$input['period_code']}-01";
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $today = now()->toDateString();
        if ($periodEnd > $today) {
            throw new RepositoryConflictException('A period cannot be closed before it has ended.');
        }
        $existing = AccountingPeriod::where('organisation_id', $organisation->id)->where('period_code', $input['period_code'])->first();
        if ($existing && $existing->status === 'CLOSED') {
            return $this->presentPeriod($existing);
        }

        $now = now();
        $id = $existing->id ?? (string) Str::uuid();
        DB::transaction(function () use ($existing, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId, $input, $periodStart, $periodEnd) {
            if ($existing) {
                AccountingPeriod::where('id', $id)->update(['status' => 'CLOSED', 'closed_by' => $actor->id, 'closed_at' => $now]);
            } else {
                AccountingPeriod::create([
                    'id' => $id, 'organisation_id' => $organisation->id, 'period_code' => $input['period_code'],
                    'period_start' => $periodStart, 'period_end' => $periodEnd, 'status' => 'CLOSED',
                    'closed_by' => $actor->id, 'closed_at' => $now, 'created_at' => $now,
                ]);
            }
            CommandLedger::record($actor->id, 'CLOSE_ACCOUNTING_PERIOD', $idempotencyKey, $requestHash, 'ACCOUNTING_PERIOD', $id, $now);
            CommandLedger::outbox('ACCOUNTING_PERIOD', $id, 'AccountingPeriodClosed', $organisation->id, ['period_id' => $id, 'period_code' => $input['period_code'], 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'ACCOUNTING_PERIOD_CLOSED', 'ACCOUNTING_PERIOD', $id, ['organisationId' => $organisation->id, 'periodCode' => $input['period_code'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentPeriod($this->findPeriodOrFail($id, $organisation->id));
    }

    /**
     * Sums every journal_lines row regardless of its parent
     * journal_entries.status -- a REVERSED original's lines are real
     * historical postings, and the reversal's opposite lines net them to
     * zero arithmetically; excluding REVERSED entries would double-count
     * the reversal's own correcting effect. as_of bounds journal_date, not
     * created_at.
     *
     * @return array<string, mixed>
     */
    public function trialBalance(User $actor, ?string $requestedOrganisationId, ?string $asOf): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $asOfDate = $asOf ?? now()->toDateString();

        $rows = ChartOfAccount::query()
            ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($asOfDate) {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_entries.journal_date', '<=', $asOfDate);
            })
            ->where('chart_of_accounts.organisation_id', $organisation->id)->where('chart_of_accounts.status', 'ACTIVE')
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.account_type')
            ->orderBy('chart_of_accounts.code')
            ->selectRaw('chart_of_accounts.id as account_id, chart_of_accounts.code, chart_of_accounts.name, chart_of_accounts.account_type, COALESCE(SUM(journal_lines.debit_cents),0) as total_debit_cents, COALESCE(SUM(journal_lines.credit_cents),0) as total_credit_cents')
            ->get();

        $accounts = $rows->map(fn ($row) => [
            'account_id' => $row->account_id, 'code' => $row->code, 'name' => $row->name, 'account_type' => $row->account_type,
            'total_debit_cents' => (int) $row->total_debit_cents, 'total_credit_cents' => (int) $row->total_credit_cents,
            'balance_cents' => (int) $row->total_debit_cents - (int) $row->total_credit_cents,
        ])->values()->all();
        $totalDebitCents = array_sum(array_column($accounts, 'total_debit_cents'));
        $totalCreditCents = array_sum(array_column($accounts, 'total_credit_cents'));

        return [
            'organisation_id' => $organisation->id, 'as_of' => $asOfDate, 'accounts' => $accounts,
            'total_debit_cents' => $totalDebitCents, 'total_credit_cents' => $totalCreditCents, 'balanced' => $totalDebitCents === $totalCreditCents,
        ];
    }

    /**
     * A deliberately simplified pair of reports, proportionate to this
     * module's own "lighter CRUD standard" -- not a full general-ledger
     * closing cycle. Income statement: revenue minus expense summed over
     * [from, to]. Balance sheet: asset/liability/equity balances as of
     * `to`, plus the same-range net income folded in as a computed
     * "retained earnings" line -- there is no period-end closing journal
     * that actually zeroes revenue/expense into equity, so this stays a
     * live computed view rather than a posted closing entry.
     *
     * @return array<string, mixed>
     */
    public function financialStatements(User $actor, ?string $requestedOrganisationId, string $from, string $to): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        $rows = ChartOfAccount::query()
            ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($from, $to) {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')->whereBetween('journal_entries.journal_date', [$from, $to]);
            })
            ->where('chart_of_accounts.organisation_id', $organisation->id)->where('chart_of_accounts.status', 'ACTIVE')
            ->groupBy('chart_of_accounts.account_type')
            ->selectRaw('chart_of_accounts.account_type, COALESCE(SUM(journal_lines.debit_cents),0) as total_debit_cents, COALESCE(SUM(journal_lines.credit_cents),0) as total_credit_cents')
            ->get()->keyBy('account_type');

        $debit = fn (string $type) => (int) ($rows[$type]->total_debit_cents ?? 0);
        $credit = fn (string $type) => (int) ($rows[$type]->total_credit_cents ?? 0);

        $revenueCents = $credit('REVENUE') - $debit('REVENUE');
        $expenseCents = $debit('EXPENSE') - $credit('EXPENSE');
        $netIncomeCents = $revenueCents - $expenseCents;
        $assetCents = $debit('ASSET') - $credit('ASSET');
        $liabilityCents = $credit('LIABILITY') - $debit('LIABILITY');
        $equityCents = $credit('EQUITY') - $debit('EQUITY');

        return [
            'organisation_id' => $organisation->id, 'from' => $from, 'to' => $to,
            'income_statement' => ['revenue_cents' => $revenueCents, 'expense_cents' => $expenseCents, 'net_income_cents' => $netIncomeCents],
            'balance_sheet' => [
                'assets_cents' => $assetCents, 'liabilities_cents' => $liabilityCents, 'equity_cents' => $equityCents,
                'retained_earnings_cents' => $netIncomeCents, 'total_liabilities_and_equity_cents' => $liabilityCents + $equityCents + $netIncomeCents,
                'balanced' => $assetCents === $liabilityCents + $equityCents + $netIncomeCents,
            ],
        ];
    }

    // -- internals --

    /** A posting into a closed accounting period is refused -- the one piece of real teeth ClosePeriod has. */
    private function assertPeriodOpen(string $organisationId, string $date): void
    {
        $periodCode = mb_substr($date, 0, 7);
        $period = AccountingPeriod::where('organisation_id', $organisationId)->where('period_code', $periodCode)->first();
        if ($period?->status === 'CLOSED') {
            throw new RepositoryConflictException("Accounting period {$periodCode} is closed to new postings.");
        }
    }

    private function requireOwnedAccount(string $accountId, string $organisationId): void
    {
        $exists = ChartOfAccount::where('id', $accountId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Account does not exist in the authorised organisation.', 422);
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

    private function insertLines(string $journalEntryId, array $lines): void
    {
        foreach ($lines as $index => $line) {
            JournalLine::create([
                'id' => (string) Str::uuid(), 'journal_entry_id' => $journalEntryId, 'line_number' => $index + 1,
                'account_id' => $line['account_id'], 'branch_id' => $line['branch_id'], 'project_id' => $line['project_id'],
                'description' => $line['description'], 'debit_cents' => $line['debit_cents'], 'credit_cents' => $line['credit_cents'],
                'tax_code' => $line['tax_code'],
            ]);
        }
    }

    private function findEntryOrFail(string $id, string $organisationId): array
    {
        $entry = JournalEntry::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $entry) {
            throw new BusinessResourceException('Journal entry was not found in the authorised organisation.', 404);
        }

        return $this->presentEntry($entry);
    }

    private function presentEntry(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id, 'organisation_id' => $entry->organisation_id, 'journal_number' => $entry->journal_number,
            'journal_date' => $entry->journal_date->toDateString(), 'reference' => $entry->reference, 'description' => $entry->description,
            'currency' => $entry->currency, 'status' => $entry->status, 'source_type' => $entry->source_type, 'source_id' => $entry->source_id,
            'created_by' => $entry->created_by, 'posted_by' => $entry->posted_by,
            'created_at' => optional($entry->created_at)->toISOString(), 'posted_at' => optional($entry->posted_at)->toISOString(),
            'reverses_journal_entry_id' => $entry->reverses_journal_entry_id,
            'lines' => JournalLine::where('journal_entry_id', $entry->id)->orderBy('line_number')->get()->map(fn (JournalLine $l) => [
                'line_number' => $l->line_number, 'account_id' => $l->account_id, 'branch_id' => $l->branch_id, 'project_id' => $l->project_id,
                'description' => $l->description, 'debit_cents' => (int) $l->debit_cents, 'credit_cents' => (int) $l->credit_cents, 'tax_code' => $l->tax_code,
            ])->values()->all(),
        ];
    }

    private function findAccountOrFail(string $id, string $organisationId): ChartOfAccount
    {
        $account = ChartOfAccount::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $account) {
            throw new BusinessResourceException('Account was not found in the authorised organisation.', 404);
        }

        return $account;
    }

    private function presentAccount(ChartOfAccount $account): array
    {
        return [
            'id' => $account->id, 'organisation_id' => $account->organisation_id, 'code' => $account->code, 'name' => $account->name,
            'account_type' => $account->account_type, 'currency' => $account->currency, 'control_type' => $account->control_type,
            'status' => $account->status, 'created_at' => optional($account->created_at)->toISOString(),
        ];
    }

    private function findPeriodOrFail(string $id, string $organisationId): AccountingPeriod
    {
        $period = AccountingPeriod::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $period) {
            throw new BusinessResourceException('Accounting period was not found in the authorised organisation.', 404);
        }

        return $period;
    }

    private function presentPeriod(AccountingPeriod $period): array
    {
        return [
            'id' => $period->id, 'organisation_id' => $period->organisation_id, 'period_code' => $period->period_code,
            'period_start' => $period->period_start->toDateString(), 'period_end' => $period->period_end->toDateString(),
            'status' => $period->status, 'closed_by' => $period->closed_by, 'closed_at' => optional($period->closed_at)->toISOString(),
            'created_at' => optional($period->created_at)->toISOString(),
        ];
    }
}
