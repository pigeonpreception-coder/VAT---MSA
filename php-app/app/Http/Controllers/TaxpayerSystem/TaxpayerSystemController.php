<?php

namespace App\Http\Controllers\TaxpayerSystem;

use App\Http\Controllers\Controller;
use App\Services\TaxpayerSystem\TaxpayerSystemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/taxpayer-systems/route.ts and its [id],
 * [id]/sync, [id]/suspension, [id]/approval siblings (lib/api/
 * taxpayer-system.ts's handleTaxpayerSystemCommand/handleTaxpayerSystemList/
 * handleTaxpayerSystemGet) -- the NamRA e-VAT MS Registered Taxpayer
 * Systems Framework.
 */
class TaxpayerSystemController extends Controller
{
    public function __construct(private readonly TaxpayerSystemService $systems) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'taxpayer-systems:read');

        return response()->json(['resources' => $this->systems->index($request->user())]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'taxpayer-systems:read');

        return response()->json(['resource' => $this->systems->show($id, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'taxpayer-systems:manage');

        $resource = $this->systems->register((array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'taxpayer-systems:approve');

        $resource = $this->systems->approve($id, $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'taxpayer-systems:manage');

        $resource = $this->systems->suspend($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource]);
    }

    public function sync(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'taxpayer-systems:manage');

        $resource = $this->systems->recordSync($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource]);
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
