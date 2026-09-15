<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\MfaTotpCredential;
use App\Models\StepUpEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\Totp;
use App\Support\Business\CommandLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/mfa-repository.ts -- Security fix 2026-08-27
 * (SECURITY_GAP_ASSESSMENT.md item #2): a real, server-verified step-up
 * mechanism. Enrolment (enrollTotp/verifyTotpEnrollment) and step-up
 * confirmation (confirmStepUp) are self-service -- every actor manages
 * their own MFA credential, so no additional permission gate beyond being
 * authenticated is appropriate here, matching resolveTaxpayer-style
 * self-scoped commands elsewhere in this codebase (the JSON API layer
 * still requires identity:read, the same near-universal gate the source
 * itself uses).
 *
 * Deliberately no idempotency-key mechanism, matching the source's own
 * explicit design choice: a TOTP code's own anti-replay check
 * (last_used_counter) already makes a retried request with the same code
 * fail exactly as it should -- a genuine retry requires a fresh code, and
 * an idempotency key would work against that, not with it.
 *
 * Built in two deliberate stages (2026-09-15, user's own explicit scope
 * decisions): first as infrastructure only -- a complete, working,
 * self-service TOTP feature with the existing ~44 step-up-gated routes
 * still on Laravel's own `password.confirm` -- then cut over the same day
 * once explicitly requested: every one of those routes now wears
 * App\Http\Middleware\EnsureFreshStepUp, which reads hasFreshStepUp()
 * below, and the old ConfirmPasswordController is gone. See
 * docs/LAUNCH_READINESS_BACKLOG.md item #8.
 */
class MfaService
{
    private const STEP_UP_WINDOW_SECONDS = 5 * 60;

    /** EnrollTotp: (re)starts enrolment with a fresh secret. Refuses to overwrite an already-ACTIVE credential -- a caller must go through a separate reset/disable path first (not yet built; today an ACTIVE credential is permanent, which is the safer default). */
    public function enrollTotp(User $actor, string $correlationId): array
    {
        $existing = MfaTotpCredential::find($actor->id);
        if ($existing?->status === 'ACTIVE') {
            throw new RepositoryConflictException('MFA is already enrolled for this account.');
        }

        $secret = Totp::generateSecret();
        $now = now();
        DB::transaction(function () use ($actor, $secret, $now, $correlationId) {
            MfaTotpCredential::updateOrCreate(
                ['user_id' => $actor->id],
                ['secret_base32' => $secret, 'status' => 'PENDING_VERIFICATION', 'last_used_counter' => null, 'created_at' => $now, 'verified_at' => null],
            );
            CommandLedger::outbox('MFA_CREDENTIAL', $actor->id, 'MfaTotpEnrollmentStarted', $actor->id, ['userId' => $actor->id, 'correlationId' => $correlationId], $now);
            AuditService::append($actor, 'MFA_TOTP_ENROLLMENT_STARTED', 'MFA_CREDENTIAL', $actor->id, ['correlationId' => $correlationId], $now);
        });

        return ['secret' => $secret, 'otpauthUri' => Totp::authUri($secret, $actor->email)];
    }

    /** VerifyTotpEnrollment: proves the actor's authenticator app genuinely holds the enrolled secret before it becomes usable for step-up. */
    public function verifyTotpEnrollment(User $actor, mixed $payload, string $correlationId): array
    {
        $code = Totp::validateCode($payload);
        $credential = MfaTotpCredential::find($actor->id);
        if (! $credential) {
            throw new IdentityValidationException([['code' => 'MFA_NOT_ENROLLED', 'path' => '/', 'message' => 'No MFA enrolment is in progress for this account.']]);
        }
        if ($credential->status === 'ACTIVE') {
            throw new RepositoryConflictException('MFA is already active for this account.');
        }
        $matchedCounter = Totp::verifyCode($credential->secret_base32, $code);
        if ($matchedCounter === null) {
            throw new IdentityValidationException([['code' => 'CODE_INCORRECT', 'path' => '/code', 'message' => 'The verification code is incorrect or has expired.']]);
        }

        $now = now();
        DB::transaction(function () use ($actor, $credential, $matchedCounter, $now, $correlationId) {
            $credential->update(['status' => 'ACTIVE', 'last_used_counter' => $matchedCounter, 'verified_at' => $now]);
            CommandLedger::outbox('MFA_CREDENTIAL', $actor->id, 'MfaTotpEnrolled', $actor->id, ['userId' => $actor->id, 'correlationId' => $correlationId], $now);
            AuditService::append($actor, 'MFA_TOTP_ENROLLED', 'MFA_CREDENTIAL', $actor->id, ['correlationId' => $correlationId], $now);
        });

        return ['status' => 'ACTIVE'];
    }

    /**
     * ConfirmStepUp: writes a real step_up_events row that
     * App\Support\Access\StepUp-equivalent freshness checks can verify
     * server-side. matchedCounter must exceed the credential's own
     * last_used_counter -- the standard TOTP anti-replay rule -- so the
     * exact same code (or an earlier one, e.g. from a captured request)
     * can never confirm step-up twice.
     */
    public function confirmStepUp(User $actor, mixed $payload, string $correlationId): array
    {
        $code = Totp::validateCode($payload);
        $credential = MfaTotpCredential::find($actor->id);
        if (! $credential || $credential->status !== 'ACTIVE') {
            throw new IdentityValidationException([['code' => 'MFA_NOT_ACTIVE', 'path' => '/', 'message' => 'Multi-factor authentication is not enrolled for this account; step-up cannot be confirmed.']]);
        }
        $matchedCounter = Totp::verifyCode($credential->secret_base32, $code);
        if ($matchedCounter === null || ($credential->last_used_counter !== null && $matchedCounter <= $credential->last_used_counter)) {
            throw new IdentityValidationException([['code' => 'CODE_INCORRECT', 'path' => '/code', 'message' => 'The verification code is incorrect, expired, or has already been used.']]);
        }

        $now = now();
        $expiresAt = $now->clone()->addSeconds(self::STEP_UP_WINDOW_SECONDS);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($actor, $credential, $matchedCounter, $now, $expiresAt, $id, $correlationId) {
            $credential->update(['last_used_counter' => $matchedCounter]);
            StepUpEvent::create(['id' => $id, 'user_id' => $actor->id, 'method' => 'TOTP', 'verified_at' => $now, 'expires_at' => $expiresAt]);
            CommandLedger::outbox('STEP_UP', $id, 'StepUpConfirmed', $actor->id, ['userId' => $actor->id, 'expiresAt' => $expiresAt->toIso8601String(), 'correlationId' => $correlationId], $now);
            AuditService::append($actor, 'STEP_UP_CONFIRMED', 'STEP_UP_EVENT', $id, ['expiresAt' => $expiresAt->toIso8601String(), 'correlationId' => $correlationId], $now);
        });

        return ['method' => 'TOTP', 'expiresAt' => $expiresAt->toIso8601String()];
    }

    /** The one read App\Support\Access\StepUp-equivalent freshness checks actually need -- a fast, read-only freshness check. */
    public function hasFreshStepUp(string $userId): bool
    {
        return StepUpEvent::where('user_id', $userId)->where('expires_at', '>', now())->exists();
    }

    /** GetAssurance's read of the caller's own MFA/step-up posture. */
    public function getMfaStatus(string $userId): array
    {
        $credential = MfaTotpCredential::find($userId);

        return ['enrolled' => $credential?->status === 'ACTIVE', 'hasRecentStepUp' => $this->hasFreshStepUp($userId)];
    }
}
