<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/security/request.ts's RequestGuardError, the
 * RATE_LIMIT_EXCEEDED/PAYLOAD_TOO_LARGE branch specifically (the other
 * branches -- CONTENT_TYPE_REQUIRED/EMPTY_BODY/INVALID_JSON -- are Laravel
 * framework concerns already handled by its own request pipeline, not
 * reimplemented here). Carries the same $retryAfterSeconds the source
 * attaches to a breached bucket's own window, surfaced as a real
 * Retry-After header.
 *
 * Property is `$errorCode`, not `$code`: `\Exception` already declares a
 * non-readonly `$code` (its numeric exception code), and PHP fatals on a
 * subclass redeclaring an inherited property as `readonly` -- the same
 * pitfall AuthorityGovernanceValidationException's own doc comment
 * documents, caught here the same way (via `php artisan test`, not
 * `php -l`, which does not catch it).
 */
class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'RATE_LIMIT_EXCEEDED',
        private readonly ?int $retryAfterSeconds = null,
        private readonly int $status = Response::HTTP_TOO_MANY_REQUESTS,
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function render(Request $request): JsonResponse
    {
        $response = response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);

        if ($this->retryAfterSeconds !== null) {
            $response->header('Retry-After', (string) $this->retryAfterSeconds);
        }

        return $response;
    }
}
