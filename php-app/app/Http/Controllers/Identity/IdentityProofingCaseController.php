<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ported from app/api/v1/identity-proofing-cases/route.ts. */
class IdentityProofingCaseController extends Controller
{
    public function __construct(private readonly RegistrationService $registrations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'registrations:read');

        return response()->json(['identity_proofing_cases' => $this->registrations->listProofingCases($request->user())]);
    }
}
