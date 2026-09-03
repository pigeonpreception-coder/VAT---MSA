<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A variable-status resource error (404 not found, 409/422 invalid reference) for the Authority Governance module, matching App\Exceptions\ComplianceResourceException's own established shape for the sibling domain. */
class AuthorityGovernanceResourceException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => $this->status === 404 ? 'RESOURCE_NOT_FOUND' : 'RESOURCE_INVALID',
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
