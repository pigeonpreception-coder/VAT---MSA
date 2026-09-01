<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/domain/control-plane.ts's ControlPlaneValidationError --
 * a single {code, message} pair, unlike most of this migration's other
 * validation exceptions (a {code, path, message}[] list). Kept faithful to
 * that shape rather than force-fitting the list convention: the source
 * itself never gives this error a `path`, since every failure here names
 * the whole command (an invalid action, an illegal state transition, an
 * unknown plan), not one field within a larger document.
 */
class LicensingValidationException extends \RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
