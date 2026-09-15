<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/identity/mfa/totp/route.ts, its verification
 * sibling, app/api/v1/identity/step-up/route.ts, and
 * app/api/v1/identity/assurance/route.ts. Self-service throughout --
 * every actor manages their own MFA credential and step-up state, so
 * identity:read (the same near-universal gate the source itself uses) is
 * the only permission required, not a per-target-resource authorization
 * check.
 */
class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    public function enroll(Request $request): JsonResponse
    {
        $this->authorize('permission', 'identity:read');

        $enrollment = $this->mfa->enrollTotp($request->user(), (string) Str::uuid());

        return response()->json(['enrollment' => $enrollment], 201);
    }

    public function verifyEnrollment(Request $request): JsonResponse
    {
        $this->authorize('permission', 'identity:read');

        $credential = $this->mfa->verifyTotpEnrollment($request->user(), $request->json()->all(), (string) Str::uuid());

        return response()->json(['credential' => $credential]);
    }

    public function confirmStepUp(Request $request): JsonResponse
    {
        $this->authorize('permission', 'identity:read');

        $stepUp = $this->mfa->confirmStepUp($request->user(), $request->json()->all(), (string) Str::uuid());

        return response()->json(['step_up' => $stepUp], 201);
    }

    public function assurance(Request $request): JsonResponse
    {
        $this->authorize('permission', 'identity:read');

        $status = $this->mfa->getMfaStatus($request->user()->id);

        return response()->json(['mfaEnrolled' => $status['enrolled'], 'hasRecentStepUp' => $status['hasRecentStepUp']]);
    }
}
