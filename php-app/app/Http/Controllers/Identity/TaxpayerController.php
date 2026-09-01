<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\SuspendTaxpayerRequest;
use App\Services\Identity\TaxpayerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Ported from app/api/v1/taxpayers/[id]/suspension/route.ts. */
class TaxpayerController extends Controller
{
    public function __construct(private readonly TaxpayerService $taxpayers) {}

    public function suspend(SuspendTaxpayerRequest $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'taxpayers:suspend');
        // Step-up: see routes/web.php's 'password.confirm' middleware on this route.

        $suspension = $this->taxpayers->suspend($request->user(), $id, $request->validated('reason'), (string) Str::uuid());

        return response()->json(['suspension' => $suspension]);
    }
}
