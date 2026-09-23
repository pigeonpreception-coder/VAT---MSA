<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\SuspendTaxpayerRequest;
use App\Services\Identity\TaxpayerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/taxpayers/[id]/suspension/route.ts and the
 * sibling .../identifiers/[identifierId]/correction, .../identifiers/
 * verification routes (Module 1 Taxpayer IdentifierVersion / correction and
 * VerifyIdentifiers).
 */
class TaxpayerController extends Controller
{
    public function __construct(private readonly TaxpayerService $taxpayers) {}

    /**
     * Ported from lib/data/repository.ts's listTaxpayers -- the source's
     * own page-only read (no `app/api/v1/taxpayers/route.ts` exists),
     * exposed as a JSON endpoint here anyway, matching this migration's
     * own established "every repository function gets a JSON endpoint"
     * convention.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'taxpayers:read');

        return response()->json(['taxpayers' => $this->taxpayers->list()]);
    }

    public function suspend(SuspendTaxpayerRequest $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'taxpayers:suspend');
        // Step-up: see routes/web.php's 'step-up' middleware on this route.

        $suspension = $this->taxpayers->suspend($request->user(), $id, $request->validated('reason'), (string) Str::uuid());

        return response()->json(['suspension' => $suspension]);
    }

    /**
     * Reuses taxpayers:suspend as its permission ceiling, matching source's
     * own doc comment on the correction route: correcting a canonical VAT
     * number or TIN is at least as consequential as suspending the
     * taxpayer outright.
     */
    public function correctIdentifier(Request $request, string $id, string $identifierId): JsonResponse
    {
        $this->authorize('permission', 'taxpayers:suspend');
        // Step-up: see routes/web.php's 'step-up' middleware on this route.

        $correction = $this->taxpayers->correctIdentifier($request->user(), $id, $identifierId, (array) $request->json()->all(), (string) Str::uuid());

        return response()->json(['correction' => $correction]);
    }

    /**
     * No step-up: this only attempts an external verification call and, at
     * most, refreshes verified_at -- it never changes the identifier value
     * itself (see correctIdentifier() for that).
     */
    public function verifyIdentifiers(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'taxpayers:read');

        $verification = $this->taxpayers->verifyIdentifiers($request->user(), $id, (string) Str::uuid());

        return response()->json(['verification' => $verification]);
    }
}
