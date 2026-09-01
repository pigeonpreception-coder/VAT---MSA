<?php

namespace App\Http\Controllers\VatRule;

use App\Http\Controllers\Controller;
use App\Services\VatRule\VatRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/vat-rules/route.ts and its evaluate/[id]/approval
 * siblings (Module 2 Phase A, in full). ProposeVatRule/ApproveVatRule are
 * step-up gated via the same 'password.confirm' middleware every other
 * sensitive command in this migration uses (see routes/web.php) --
 * matching the source's own requireStepUp call on both.
 */
class VatRuleController extends Controller
{
    public function __construct(private readonly VatRuleService $vatRules) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'vat-rules:read');

        return response()->json(['rules' => $this->vatRules->list()]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $this->authorize('permission', 'vat-rules:read');

        $evaluation = $this->vatRules->evaluate($request->query('tax_category'), $request->query('date'));

        return response()->json(['evaluation' => $evaluation]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'vat-rules:manage');
        $correlationId = (string) Str::uuid();
        $rule = $this->vatRules->propose((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['rule' => $rule], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'vat-rules:manage');
        $correlationId = (string) Str::uuid();
        $rule = $this->vatRules->approve($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['rule' => $rule], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
