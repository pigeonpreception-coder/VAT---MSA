<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/refunds/[id]/payment/route.ts,
 * .../payment/allocation/route.ts and app/api/v1/payments/outstanding/route.ts
 * (lib/api/payment.ts's handlePaymentCommand/handleOutstandingRefunds).
 * Kept as its own controller rather than folded into RefundController --
 * the source keeps Payment "its own catalogue domain... not a sub-resource
 * of Compliance" (lib/api/payment.ts's own doc comment), and this port
 * follows that structural choice.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function record(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'payments:record');

        $resource = $this->payments->recordPayment($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource], 201);
    }

    public function allocate(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'payments:record');

        $resource = $this->payments->allocatePayment($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource]);
    }

    public function outstanding(Request $request): JsonResponse
    {
        $this->authorize('permission', 'payments:read');

        return response()->json($this->payments->getOutstanding($request->user()));
    }

    private function idempotencyKey(Request $request): string
    {
        return (string) $request->header('Idempotency-Key', '');
    }

    private function correlationId(): string
    {
        return (string) Str::uuid();
    }
}
