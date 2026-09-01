<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ported from lib/data/vat-lifecycle-repository.ts's VatLifecycleResourceError -- a variable-status resource error (404 not found, 422 invalid reference/state by default). */
class VatLifecycleResourceException extends \RuntimeException
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
