<?php

namespace App\Support\Security;

use App\Exceptions\RateLimitExceededException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/security/request.ts's enforceRateLimits and its family
 * of named bucket-builder wrappers -- multi-level actor/device/source/
 * tenant/global rate budgets, never ported to this migration until now
 * (called out as an explicitly deferred, orthogonal concern in
 * InvoiceController/DocumentController/several others' own doc comments,
 * and confirmed still missing by a full-repo `grep -rln "RateLimiter::for\|
 * throttle:"` finding nothing but Laravel's own generic `throttle:api`
 * framework default). Backed by the same `rate_limit_windows` table the
 * source's own migration already ported (this table existed with no
 * consumer -- see that migration's own doc comment), using a fixed-window
 * counter per bucket rather than a sliding one, exactly matching the
 * source's own `Math.floor(nowMs / (windowSeconds * 1000))` windowing.
 *
 * The source's single D1 `db.batch()` INSERT..ON CONFLICT..RETURNING
 * round-trip becomes MySQL's own `INSERT..ON DUPLICATE KEY UPDATE
 * ..LAST_INSERT_ID(expr)` idiom below -- one round trip per bucket,
 * returning the post-increment count without a second SELECT.
 *
 * `LAST_INSERT_ID(expr)` is called on BOTH branches, not just the
 * UPDATE one: this table has no AUTO_INCREMENT column (deliberately, see
 * this class's own doc comment above), so MySQL never sets the
 * connection's LAST_INSERT_ID() on a genuine first INSERT -- only the
 * explicit `LAST_INSERT_ID(expr)` call does that, on whichever branch
 * actually runs. Missing it on the VALUES clause (an earlier version of
 * this fix) left `SELECT LAST_INSERT_ID()` reading a stale value from an
 * unrelated prior statement on the same connection, not this bucket's own
 * count -- caught by a real PHPUnit run throwing on a bucket's very first,
 * well-under-limit request.
 */
class RateLimitGuard
{
    /** @param list<array{key: string, limit: int, windowSeconds: int}> $buckets */
    public static function enforce(array $buckets, ?int $nowMs = null): void
    {
        $nowMs ??= (int) (microtime(true) * 1000);
        foreach ($buckets as $bucket) {
            $windowStart = intdiv($nowMs, $bucket['windowSeconds'] * 1000);
            $expiresAt = ($windowStart + 2) * $bucket['windowSeconds'];
            DB::statement(
                'INSERT INTO rate_limit_windows (bucket_key, window_start, request_count, expires_at) '.
                'VALUES (?, ?, LAST_INSERT_ID(1), ?) '.
                'ON DUPLICATE KEY UPDATE request_count = LAST_INSERT_ID(request_count + 1)',
                [$bucket['key'], $windowStart, $expiresAt],
            );
            $count = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS count')->count;
            if ($count > $bucket['limit']) {
                throw new RateLimitExceededException(
                    'Request rate exceeded the protected submission threshold.',
                    'RATE_LIMIT_EXCEEDED',
                    $bucket['windowSeconds'],
                );
            }
        }
        // Source's own `if (nowMs % 97 === 0)` opportunistic sweep -- a cheap,
        // no-cron-infrastructure-needed way to keep the table from growing
        // unboundedly, matching this codebase's established "no queue/cron
        // in this deployment" posture (see outbox_events' own migration
        // comment) rather than adding a scheduled command for it.
        if ($nowMs % 97 === 0) {
            DB::table('rate_limit_windows')->where('expires_at', '<', intdiv($nowMs, 1000))->delete();
        }
    }

    private static function tenantKey(User $user): string
    {
        return $user->taxpayer_id ?? "role:{$user->role}";
    }

    public static function enforceInvoice(User $user, string $deviceId, string $sourceToken): void
    {
        $tenant = self::tenantKey($user);
        self::enforce([
            ['key' => "invoice:actor:{$user->id}", 'limit' => 120, 'windowSeconds' => 60],
            ['key' => "invoice:device:{$deviceId}", 'limit' => 180, 'windowSeconds' => 60],
            ['key' => "invoice:source:{$sourceToken}", 'limit' => 240, 'windowSeconds' => 60],
            ['key' => "invoice:tenant:{$tenant}", 'limit' => 600, 'windowSeconds' => 60],
            ['key' => 'invoice:global', 'limit' => 5_000, 'windowSeconds' => 60],
        ]);
    }

    public static function enforceRegistration(User $user, string $sourceToken): void
    {
        $tenant = self::tenantKey($user);
        self::enforce([
            ['key' => "registration:actor:{$user->id}", 'limit' => 10, 'windowSeconds' => 300],
            ['key' => "registration:source:{$sourceToken}", 'limit' => 20, 'windowSeconds' => 300],
            ['key' => "registration:tenant:{$tenant}", 'limit' => 30, 'windowSeconds' => 300],
            ['key' => 'registration:global', 'limit' => 500, 'windowSeconds' => 300],
        ]);
    }

    /**
     * Ported from lib/security/request.ts's enforceSelfServeSignupSourceRateLimits
     * -- the one bucket set with no authenticated $user at all (self-serve
     * signup is genuinely unauthenticated), so it takes a raw source/device
     * token pair instead of tenantKey()'s own User-derived key.
     */
    public static function enforceSelfServeSignup(string $sourceToken, string $deviceId): void
    {
        self::enforce([
            ['key' => "self-serve-signup:source:{$sourceToken}", 'limit' => 10, 'windowSeconds' => 300],
            ['key' => "self-serve-signup:device:{$deviceId}", 'limit' => 15, 'windowSeconds' => 300],
            ['key' => 'self-serve-signup:global', 'limit' => 500, 'windowSeconds' => 300],
        ]);
    }

    /** Ported from lib/security/request.ts's enforceSelfServeSignupEmailRateLimit -- a second, email-keyed limit alongside enforceSelfServeSignup, bounding repeated attempts against one contact email regardless of source/device. */
    public static function enforceSelfServeSignupEmail(string $email): void
    {
        self::enforce([
            ['key' => 'self-serve-signup:email:'.mb_strtolower(trim($email)), 'limit' => 5, 'windowSeconds' => 3_600],
        ]);
    }

    /**
     * Gap-finding pass (2026-09-24), rate-limit-boundary angle:
     * `lib/security/request.ts` defines this bucket set (and
     * enforceVerifyTokenRateLimits() below) with an explicit security
     * rationale in its own doc comment -- "claimInvitation is a
     * token-guessing surface (Sec 18)" -- but never actually calls either
     * one from its own route handler (`app/api/v1/invitations/claim/
     * route.ts` calls straight into `claimInvitation` with no rate-limit
     * call at all). That is a pre-existing gap in the source itself, not
     * a Laravel-side regression -- this port has nothing to diverge from
     * here. Wiring it up anyway (rather than faithfully reproducing the
     * same unused-dead-code state) because the underlying vulnerability
     * is real and reachable (an unauthenticated actor can submit an
     * unbounded number of guesses against `ClaimInvitationRequest`'s own
     * token field) and the infrastructure to close it already exists,
     * unlike a genuinely new feature this session would otherwise avoid
     * inventing. Source/device bucket keying uses
     * RequestContext::unauthenticatedRequestIp(), not sourceToken()/
     * deviceId() -- see that method's own doc comment for why a
     * pre-auth caller's own headers cannot be trusted as the sole
     * rate-limit signal.
     */
    public static function enforceInvitationClaimRateLimits(string $sourceToken, string $deviceId): void
    {
        self::enforce([
            ['key' => "invitation-claim:source:{$sourceToken}", 'limit' => 10, 'windowSeconds' => 300],
            ['key' => "invitation-claim:device:{$deviceId}", 'limit' => 15, 'windowSeconds' => 300],
            ['key' => 'invitation-claim:global', 'limit' => 200, 'windowSeconds' => 300],
        ]);
    }

    /**
     * Gap-finding pass (2026-09-24) -- see enforceInvitationClaimRateLimits()'s
     * own doc comment for the full explanation; same situation, same fix
     * rationale. Source's own doc comment: "GET /api/v1/verify/[token] is
     * a public, unauthenticated, cached certificate-lookup endpoint -- an
     * enumeration surface (Sec 18)" -- again defined in
     * lib/security/request.ts but never called from
     * `app/api/v1/verify/[token]/route.ts` itself.
     */
    public static function enforceVerifyTokenRateLimits(string $sourceToken, string $deviceId): void
    {
        self::enforce([
            ['key' => "verify-token:source:{$sourceToken}", 'limit' => 30, 'windowSeconds' => 60],
            ['key' => "verify-token:device:{$deviceId}", 'limit' => 45, 'windowSeconds' => 60],
            ['key' => 'verify-token:global', 'limit' => 2_000, 'windowSeconds' => 60],
        ]);
    }

    /**
     * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #8, ported
     * verbatim): the generic per-command actor/tenant/global bucket shape
     * the identity/control-plane/reconciliation/vat-rule route families
     * all share -- the source's own commandRateLimitBuckets(), reused here
     * as one method instead of four thin wrappers differing only in a
     * literal family-name string.
     */
    public static function enforceCommand(string $family, string $command, User $user): void
    {
        $tenant = self::tenantKey($user);
        self::enforce([
            ['key' => "{$family}:{$command}:actor:{$user->id}", 'limit' => 30, 'windowSeconds' => 60],
            ['key' => "{$family}:{$command}:tenant:{$tenant}", 'limit' => 120, 'windowSeconds' => 60],
            ['key' => "{$family}:{$command}:global", 'limit' => 1_000, 'windowSeconds' => 60],
        ]);
    }
}
