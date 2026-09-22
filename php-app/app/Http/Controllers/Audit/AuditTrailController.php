<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from lib/api/audit.ts's handleAuditTrailSearch/
 * handleAuditChainVerificationList/handleAuditChainVerificationTrigger
 * (lib/data/audit-repository.ts's searchAuditTrail/
 * listAuditChainVerifications/runAuditChainVerification) -- Module 8
 * Phase D. All three are gated on `audit:read` only, matching source's
 * own operationClass for every one of them (including the verification
 * trigger) being READ, not WRITE -- verifying an already-append-only
 * audit log mutates no business data, so no `step-up` here.
 */
class AuditTrailController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $this->authorize('permission', 'audit:read');

        $params = $request->query();
        $result = AuditService::searchTrail([
            'resource_type' => isset($params['resource_type']) ? mb_strtoupper(trim((string) $params['resource_type'])) : null,
            'resource_id' => isset($params['resource_id']) ? trim((string) $params['resource_id']) : null,
            'action' => isset($params['action']) ? mb_strtoupper(trim((string) $params['action'])) : null,
            'actor_id' => isset($params['actor_id']) ? trim((string) $params['actor_id']) : null,
            'limit' => isset($params['limit']) ? (int) $params['limit'] : null,
            'offset' => isset($params['offset']) ? (int) $params['offset'] : null,
        ]);

        return response()->json([
            'items' => $result['items']->values(), 'total_count' => $result['total_count'],
            'limit' => $result['limit'], 'offset' => $result['offset'],
        ]);
    }

    public function chainVerifications(Request $request): JsonResponse
    {
        $this->authorize('permission', 'audit:read');

        $limit = $request->query('limit') ? (int) $request->query('limit') : 50;

        return response()->json(['verifications' => AuditService::listChainVerifications($limit)]);
    }

    public function verifyChain(Request $request): JsonResponse
    {
        $this->authorize('permission', 'audit:read');

        $verification = AuditService::runChainVerification($request->user(), (string) Str::uuid());

        return response()->json(['verification' => $verification], 201);
    }
}
