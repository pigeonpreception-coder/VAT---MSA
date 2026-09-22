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
        // Not sourceToken()/deviceId() -- this route is genuinely
        // unauthenticated, so a caller-supplied header is the only signal
        // a spoofed value would defeat, not a supplement to a real actor
        // identity. See RequestContext::unauthenticatedRequestIp()'s own
        // doc comment.
        $ip = RequestContext::unauthenticatedRequestIp($request);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        $accepted = $this->signup->submit($payload, $ip, $ip, $idempotencyKey);

        return response()->json($accepted, Response::HTTP_ACCEPTED, [
            'x-correlation-id' => (string) Str::uuid(),
            'cache-control' => 'no-store',
        ]);
    }
}
