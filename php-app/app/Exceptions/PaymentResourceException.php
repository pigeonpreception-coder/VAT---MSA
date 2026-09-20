<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ported from lib/data/payment-repository.ts's PaymentResourceError -- a
 * variable-status resource error (404 not found, default 422 invalid
 * reference), matching BusinessResourceException/SecurityResourceException's
 * own established shape.
 */
class PaymentResourceException extends \RuntimeException
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
