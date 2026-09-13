<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/domain/fixed-asset.ts's FixedAssetValidationError and
 * lib/domain/logistics.ts's LogisticsValidationError -- both are a list of
 * {code, path, message}, not a single message, and both get one shared
 * exception class here, matching how BusinessValidationException already
 * spans every Business sub-domain rather than getting one exception per
 * concept.
 */
class OperationsValidationException extends \RuntimeException
{
    /** @param list<array{code: string, path: string, message: string}> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Operations command failed validation.');
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
