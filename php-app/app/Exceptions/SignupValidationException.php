<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/domain/signup.ts's SignupValidationError (and
 * lib/domain/identity.ts's IdentityValidationError, folded into the same
 * submission check -- see SignupValidator::submission()). Unlike those
 * source classes (a `messages` array, every failing field at once), this
 * carries only the first failure -- matching this migration's own
 * established single-error-at-a-time *ValidationException precedent.
 */
class SignupValidationException extends \RuntimeException
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
