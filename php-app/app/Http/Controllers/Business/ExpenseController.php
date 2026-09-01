<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Business\ExpenseService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/expenses/{route,categories,report,[id]/{approval,rejection,submission}}/route.ts (Module 5 Phase E). */
class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses, private readonly OrganisationResolver $organisations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'expenses:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $items = Expense::where('organisation_id', $organisation->id)->orderByDesc('expense_date')->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['organisation_id' => $organisation->id, 'expenses' => $items]);
    }

    public function indexCategories(Request $request): JsonResponse
    {
        $this->authorize('permission', 'expenses:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $categories = ExpenseCategory::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->orderBy('name')->get();

        return response()->json(['organisation_id' => $organisation->id, 'categories' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $correlationId = (string) Str::uuid();
        $category = $this->expenses->createCategory((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $category], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $correlationId = (string) Str::uuid();
        $expense = $this->expenses->create((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $expense], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $correlationId = (string) Str::uuid();
        $expense = $this->expenses->submit($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $expense], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $correlationId = (string) Str::uuid();
        $expense = $this->expenses->approve($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $expense], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'expenses:manage');
        $correlationId = (string) Str::uuid();
        $expense = $this->expenses->reject($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $expense], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function report(Request $request): JsonResponse
    {
        $this->authorize('permission', 'expenses:read');
        $today = now()->toDateString();
        $from = $request->query('from') ?: mb_substr($today, 0, 7).'-01';
        $to = $request->query('to') ?: $today;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return response()->json(['code' => 'VALIDATION_FAILED', 'message' => 'from/to must be ISO dates (YYYY-MM-DD).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($to < $from) {
            return response()->json(['code' => 'VALIDATION_FAILED', 'message' => 'to cannot be earlier than from.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($this->expenses->report($request->user(), $request->query('organisation_id'), $from, $to));
    }
}
