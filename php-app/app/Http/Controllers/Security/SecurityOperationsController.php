<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\SecurityOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/security/incidents/{route,[id]/{route,
 * containment,revocation,closure}}/route.ts, via lib/api/security.ts's
 * handleSOCQueue/handleIncidentDetail/handleIncidentCreate/
 * handleIncidentContainment/handleIncidentRevocation/handleIncidentClosure
 * dispatch. Reuses App\Services\Security\SecurityOperationsService exactly
 * as SecurityOperationsViewController already does.
 *
 * SecurityOperationsViewController's own doc comment previously argued
 * against a JSON surface here, on the grounds that no other Blade-driven
 * admin page in this port had one either -- citing Fixed Assets and
 * Logistics by name as the same-precedent siblings. Both of those shipped
 * their own JSON APIs earlier this same session (closing an identical
 * route-level gap this source sweep found for all three together), which
 * leaves that stated rationale stale rather than still true; user
 * confirmed closing this one the same way rather than treating the
 * original omission as an intentional, still-current security tradeoff.
 * Every write route wears 'step-up' in routes/web.php, matching this
 * exact module's own already-established Blade posture (every incident-
 * management write, not only source's own narrower `revokeIncidentAccess`-
 * only gate) -- kept consistent within this one service rather than
 * reverting to source's scope or to Fixed Assets/Logistics's no-step-up
 * precedent, which is a different, less sensitive command class.
 */
class SecurityOperationsController extends Controller
{
    public function __construct(private readonly SecurityOperationsService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'security:read');
        $incidents = $this->service->getSOCQueue($request->query('status'), $request->query('severity'));

        return response()->json(['incidents' => $incidents]);
    }

    public function show(string $incident): JsonResponse
    {
        $this->authorize('permission', 'security:read');
        $detail = $this->service->getIncidentDetail($incident);

        return response()->json($detail);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'security:manage');
        $correlationId = (string) Str::uuid();
        $detail = $this->service->createIncident($request->user(), (array) $request->json()->all(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json($detail, Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function containment(Request $request, string $incident): JsonResponse
    {
        $this->authorize('permission', 'security:manage');
        $correlationId = (string) Str::uuid();
        $detail = $this->service->containIncident($incident, $request->user(), (array) $request->json()->all(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json($detail, Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function revocation(Request $request, string $incident): JsonResponse
    {
        $this->authorize('permission', 'security:manage');
        $correlationId = (string) Str::uuid();
        $detail = $this->service->revokeIncidentAccess($incident, $request->user(), (array) $request->json()->all(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json($detail, Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function closure(Request $request, string $incident): JsonResponse
    {
        $this->authorize('permission', 'security:manage');
        $correlationId = (string) Str::uuid();
        $detail = $this->service->closeIncident($incident, $request->user(), (array) $request->json()->all(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json($detail, Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
