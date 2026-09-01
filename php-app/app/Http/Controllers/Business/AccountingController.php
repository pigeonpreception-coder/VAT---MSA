<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\Business\AccountingService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/accounting/{accounts,journals,journals/[id]/reversal,
 * periods/closure,trial-balance,statements}/route.ts (Module 5 Phase C).
 */
class AccountingController extends Controller
{
    public function __construct(private readonly AccountingService $accounting, private readonly OrganisationResolver $organisations) {}

    /** Simple real query over chart_of_accounts, unlike the source's own fixed getBusinessPlatformSnapshot list -- see docs/MIGRATION_MATRIX.md's Phase 10 note on that deferred aggregate. */
    public function indexAccounts(Request $request): JsonResponse
    {
        $this->authorize('permission', 'accounting:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $accounts = ChartOfAccount::where('organisation_id', $organisation->id)->orderBy('code')->limit(200)->get();

        return response()->json(['organisation_id' => $organisation->id, 'accounts' => $accounts]);
    }

    /** Simple real query over journal_entries, unlike the source's own fixed getBusinessPlatformSnapshot list. */
    public function indexJournals(Request $request): JsonResponse
    {
        $this->authorize('permission', 'accounting:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $journals = JournalEntry::where('organisation_id', $organisation->id)->orderByDesc('journal_date')->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['organisation_id' => $organisation->id, 'journals' => $journals]);
    }

    public function storeJournal(Request $request): JsonResponse
    {
        $this->authorize('permission', 'accounting:post');
        $correlationId = (string) Str::uuid();
        $entry = $this->accounting->postJournal((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $entry], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $this->authorize('permission', 'accounting:post');
        $correlationId = (string) Str::uuid();
        $account = $this->accounting->createAccount((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $account], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function reverseJournal(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'accounting:post');
        $correlationId = (string) Str::uuid();
        $entry = $this->accounting->reverseJournalEntry($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $entry], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function closePeriod(Request $request): JsonResponse
    {
        $this->authorize('permission', 'accounting:close-period');
        $correlationId = (string) Str::uuid();
        $period = $this->accounting->closePeriod((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $period], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorize('permission', 'accounting:read');
        $asOf = $request->query('as_of');
        if ($asOf && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            return response()->json(['code' => 'VALIDATION_FAILED', 'message' => 'as_of must be an ISO date (YYYY-MM-DD).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($this->accounting->trialBalance($request->user(), $request->query('organisation_id'), $asOf));
    }

    public function statements(Request $request): JsonResponse
    {
        $this->authorize('permission', 'accounting:read');
        $today = now()->toDateString();
        $from = $request->query('from') ?: mb_substr($today, 0, 7).'-01';
        $to = $request->query('to') ?: $today;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return response()->json(['code' => 'VALIDATION_FAILED', 'message' => 'from/to must be ISO dates (YYYY-MM-DD).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($to < $from) {
            return response()->json(['code' => 'VALIDATION_FAILED', 'message' => 'to cannot be earlier than from.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($this->accounting->financialStatements($request->user(), $request->query('organisation_id'), $from, $to));
    }
}
