<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/signup-applications/route.ts's own special-cased
 * handling of SignupValidationError's COMPANY_ADMIN_AUTHORITY_REQUIRED
 * code: source computes every validation message together and checks
 * afterwards whether that one code is present anywhere in the list,
 * returning 403 instead of 422 when it is -- deliberately outranking
 * every other validation failure. This port checks
 * company_system_administrator_attested first, before any other field,
 * and throws this exception immediately rather than reproducing source's
 * compute-everything-then-prioritise shape: the observable behaviour is
 * identical for every real submission (a caller without that authority
 * gets 403 regardless of what else is wrong with the payload), and the
 * one case this simplifies away -- attested=true credited only because it
 * happened to also queue up other field errors -- cannot occur, since
 * attested=true is itself accepted unconditionally.
 */
class SignupAuthorityRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the verified Company System Administrator may start a commercial subscription application.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['code' => 'COMPANY_ADMIN_AUTHORITY_REQUIRED', 'message' => $this->getMessage()], Response::HTTP_FORBIDDEN);
    }
}
