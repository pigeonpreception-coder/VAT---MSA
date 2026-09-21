<?php

namespace App\Http\Controllers\Signup;

use App\Http\Controllers\Controller;
use App\Services\Signup\SignupService;
use App\Support\Security\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/signup-applications/route.ts (lib/data/
 * signup-repository.ts's submitSelfServeSignup) -- the self-serve
 * commercial SaaS signup channel. Genuinely unauthenticated, so this
 * lives in routes/api.php's stateless `api` middleware group (no session,
 * no CSRF) alongside PosInvoiceController -- see that file's own doc
 * comment for why every other "JSON API" in this app is deliberately
 * session-driven and this one specifically cannot be.
 */
class SignupController extends Controller
{
    public function __construct(private readonly SignupService $signup) {}

    public function store(Request $request): JsonResponse
    {
        $payload = (array) $request->json()->all();
        $sourceToken = RequestContext::sourceToken($request);
        $deviceId = RequestContext::deviceId($request);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        $accepted = $this->signup->submit($payload, $sourceToken, $deviceId, $idempotencyKey);

        return response()->json($accepted, Response::HTTP_ACCEPTED, [
            'x-correlation-id' => (string) Str::uuid(),
            'cache-control' => 'no-store',
        ]);
    }
}
