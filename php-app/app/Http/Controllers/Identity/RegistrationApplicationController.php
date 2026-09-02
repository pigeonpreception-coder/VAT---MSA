<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\DecideRegistrationRequest;
use App\Http\Requests\Identity\SubmitRegistrationRequest;
use App\Services\Audit\AuditService;
use App\Services\Identity\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Ported from app/api/v1/registration-applications/route.ts and its [id]/decision sibling. */
class RegistrationApplicationController extends Controller
{
    public function __construct(private readonly RegistrationService $registrations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'registrations:read');

        return response()->json(['registrations' => $this->registrations->list($request->user())]);
    }

    public function store(SubmitRegistrationRequest $request): JsonResponse
    {
        $this->authorize('permission', 'registrations:submit');

        $idempotencyKey = $request->header('Idempotency-Key', '');
        $correlationId = (string) Str::uuid();

        $application = $this->registrations->submit($request->normalized(), $request->user(), $idempotencyKey, $correlationId);

        return response()->json([
            'registration_id' => $application->id,
            'status' => $application->status,
            'verification_source' => $application->verification_source,
            'verification_status' => $application->verification_status ?? null,
            'submitted_at' => $application->submitted_at,
            'next_action' => 'Await authoritative ITAS/NamRA verification. No taxpayer or organisation is created until verification and approval complete.',
        ], 202);
    }

    public function decision(DecideRegistrationRequest $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'registrations:approve');
        // Step-up (source: requireStepUp, MFA) -- see routes/web.php's 'password.confirm'
        // middleware on this route: same fresh-reauthentication property via Laravel's own
        // built-in mechanism, not yet the source's TOTP step_up_events (a dedicated MFA phase).

        $decision = $this->registrations->decide(
            $request->user(),
            $id,
            $request->validated('decision'),
            $request->validated('reason'),
            (string) Str::uuid(),
        );

        return response()->json(['decision' => $decision]);
    }
}
