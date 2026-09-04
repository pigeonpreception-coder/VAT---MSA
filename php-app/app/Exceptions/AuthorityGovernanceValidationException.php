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
 *
 * Property is `$errorCode`, not `$code`: `\Exception` already declares a
 * non-readonly `$code` (its numeric exception code), and PHP fatals on a
 * subclass redeclaring an inherited property as `readonly` -- caught here
 * via `php -l` before this ever shipped. `LicensingValidationException`
 * already established `$errorCode` as this migration's own correct name
 * for the identical shape; this class just hadn't matched it.
 */
class AuthorityGovernanceValidationException extends \RuntimeException
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
