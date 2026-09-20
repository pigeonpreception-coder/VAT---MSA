<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/domain/security.ts's SecurityValidationError -- a
 * single {code, message}, matching AuthorityGovernanceValidationException's
 * own precedent (the source's own messages array is collapsed to its
 * first entry here, the same simplification every other single-error
 * *ValidationException in this migration already makes).
 */
class SecurityValidationException extends \RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['code' => $this->errorCode, 'message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
