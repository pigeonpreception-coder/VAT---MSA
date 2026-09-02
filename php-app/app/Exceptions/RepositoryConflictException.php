<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ported from lib/data/repository.ts's RepositoryConflictError -- 409, a genuine state conflict rather than a validation failure. */
class RepositoryConflictException extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'CONFLICT',
            'message' => $this->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
