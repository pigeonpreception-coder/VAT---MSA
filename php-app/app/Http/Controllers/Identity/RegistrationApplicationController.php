<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\DecideRegistrationRequest;
use App\Http\Requests\Identity\SubmitRegistrationRequest;
use App\Models\RegistrationApplication;
use App\Services\Audit\AuditService;
use App\Services\Identity\RegistrationService;
use App\Support\Access\TenantScope;
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
        $user = $request->user();

        $query = RegistrationApplication::query()->with('verifications');
        if (! TenantScope::isNational($user)) {
            $query->where('submitted_by', $user->id);
        }
        $applications = $query->orderByDesc('submitted_at')->limit(100)->get()->map(fn (RegistrationApplication $application) => [
            'id' => $application->id,
            'vat_number' => $application->vat_number,
            'tin' => $application->tin,
            'company_registration_number' => $application->company_registration_number,
            'legal_name' => $application->legal_name,
            'trading_name' => $application->trading_name,
            'taxpayer_type' => $application->taxpayer_type,
            'return_frequency' => $application->return_frequency,
            'email' => $application->email,
            'status' => $application->status,
            'verification_source' => $application->verification_source,
            'verification_status' => $application->verifications->sortByDesc('checked_at')->first()?->status,
            'submitted_by' => $application->submitted_by,
            'submitted_at' => $application->submitted_at,
        ]);

        return response()->json(['registrations' => $applications]);
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
