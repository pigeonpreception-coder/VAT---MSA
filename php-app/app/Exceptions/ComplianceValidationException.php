<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ported from lib/domain/compliance.ts's ComplianceValidationError -- a list of {code, path, message}, never a single message. */
class ComplianceValidationException extends \RuntimeException
{
    /** @param list<array{code: string, path: string, message: string}> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Compliance command failed validation.');
    }

    /** @return list<array{code: string, path: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'VALIDATION_FAILED',
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
