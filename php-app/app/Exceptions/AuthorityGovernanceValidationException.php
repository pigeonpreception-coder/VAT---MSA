<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/domain/authority-governance.ts's
 * AuthorityGovernanceValidationError -- a single {code, message}, unlike
 * App\Exceptions\ComplianceValidationException's own list-of-errors
 * shape (that module's own source error class is genuinely different).
 */
class AuthorityGovernanceValidationException extends \RuntimeException
{
    public function __construct(private readonly string $code, string $message)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['code' => $this->code, 'message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
