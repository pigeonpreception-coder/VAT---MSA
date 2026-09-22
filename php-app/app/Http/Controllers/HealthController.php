<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/health/{live,ready}/route.ts -- liveness/readiness
 * probes for a load balancer or orchestrator, not a business command.
 * Deliberately outside every auth-gated route group, same as
 * PublicVerificationController: a probe that required a session would
 * defeat its own purpose. `correlation_id` is always freshly generated
 * here rather than echoing an incoming `X-Correlation-Id` header, matching
 * every other controller in this port (e.g. ExpenseController), none of
 * which honour a caller-supplied correlation id either.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'UP', 'service' => 'vat-msa-web', 'version' => '0.3.0', 'timestamp' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function ready(): JsonResponse
    {
        try {
            DB::select('SELECT 1 AS ready');

            return response()->json([
                'status' => 'READY', 'service' => 'vat-msa-web', 'timestamp' => now()->toIso8601String(),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return response()->json([
                'status' => 'NOT_READY', 'service' => 'vat-msa-web',
            ], Response::HTTP_SERVICE_UNAVAILABLE)->withHeaders(['Cache-Control' => 'no-store', 'Retry-After' => '5']);
        }
    }
}
