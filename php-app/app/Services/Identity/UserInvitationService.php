<?php

namespace App\Services\Identity;

use App\Exceptions\RepositoryConflictException;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Audit\AuditService;
use App\Support\Security\RateLimitGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ported from lib/data/identity-repository.ts's inviteUser/claimInvitation
 * -- Module 1 Identity ProvisionUser, an explicit invite-and-claim flow
 * genuinely distinct from Phase 12 slice 2's `inviteEmployee`/
 * `activateEmployee` (which links an *already-registered* Laravel account
 * to an organisation's own employee roster; this one provisions the
 * account itself). Nothing actually delivers the claim link anywhere --
 * this repo has no outbound email integration, matching `inviteEmployee`'s
 * own DISABLED_LOCAL_STAGING delivery -- so the token is returned directly
 * to the inviting admin to relay out of band.
 *
 * `claim()` is a deliberate, documented deviation from source's own claim
 * half: `claimInvitation` there trusted a platform-asserted identity
 * (subject/email/displayName) with no password at all, the exact
 * header-trust mechanism this port's whole authentication model rejects
 * (see App\Http\Requests\Auth\LoginRequest's own doc comment) -- there is
 * no equivalent to trust here. Reworked into a real, password-setting
 * claim (the user's own explicit choice when this fork was raised): the
 * invited person visits the claim link, sets their own credentials, and
 * that creates their real Laravel account -- the same "no session ever
 * trusts an unverified assertion" posture `App\Services\Platform\
 * PlatformChangeService::provisionStaff` already established for Module 8
 * Phase A's own ProvisionStaff (a random, never-communicated password,
 * with a documented follow-up path -- here that path is a bespoke
 * claim-token form rather than the generic forgot-password flow, since
 * claiming creates the account rather than resetting an existing one's
 * credential).
 */
class UserInvitationService
{
    private const TTL_DAYS = 7;

    public function __construct(private readonly OrganisationService $organisations) {}

    /** @param array{email: string, role_code: string} $invitation */
    public function invite(User $actor, string $organisationId, array $invitation, string $correlationId): UserInvitation
    {
        $organisation = $this->organisations->requireInScope($actor, $organisationId);

        if (User::whereRaw('lower(email) = lower(?)', [$invitation['email']])->exists()) {
            throw new RepositoryConflictException('A user with this email already exists.');
        }
        if (UserInvitation::where('organisation_id', $organisationId)->whereRaw('lower(email) = lower(?)', [$invitation['email']])->where('status', 'PENDING')->exists()) {
            throw new RepositoryConflictException('A pending invitation already exists for this email in this organisation.');
        }

        $now = now();
        $id = (string) Str::uuid();
        $claimToken = str_replace('-', '', Str::uuid().Str::uuid());
        $expiresAt = $now->copy()->addDays(self::TTL_DAYS);

        DB::transaction(function () use ($id, $organisationId, $organisation, $invitation, $actor, $now, $claimToken, $expiresAt, $correlationId) {
            UserInvitation::create([
                'id' => $id, 'organisation_id' => $organisationId, 'email' => $invitation['email'], 'role_code' => $invitation['role_code'],
                'claim_token' => $claimToken, 'status' => 'PENDING', 'invited_by' => $actor->id, 'invited_at' => $now,
                'expires_at' => $expiresAt, 'claimed_at' => null, 'claimed_by_user_id' => null,
            ]);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'IDENTITY', 'aggregate_id' => $id,
                'event_type' => 'UserInvitationCreated', 'event_version' => 1, 'partition_key' => $organisation->taxpayer_id,
                'payload' => AuditService::canonicalJson([
                    'invitation_id' => $id, 'organisation_id' => $organisationId, 'email' => $invitation['email'],
                    'delivery' => 'DISABLED_LOCAL_STAGING', 'correlation_id' => $correlationId,
                ]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'USER_INVITED', 'USER_INVITATION', $id, [
                'organisationId' => $organisationId, 'email' => $invitation['email'], 'roleCode' => $invitation['role_code'],
            ], $now);
        });

        return UserInvitation::findOrFail($id);
    }

    /**
     * Gap-finding pass (2026-09-24): $sourceToken/$deviceId added --
     * see RateLimitGuard::enforceInvitationClaimRateLimits()'s own doc
     * comment for why this unauthenticated token-guessing surface now
     * gets a purpose-built rate limit it never had (in the source or
     * this port) before now.
     *
     * @return array{userId: string, organisationId: string, roleCode: string, status: string}
     */
    public function claim(string $token, string $name, string $password, string $correlationId, string $sourceToken, string $deviceId): array
    {
        RateLimitGuard::enforceInvitationClaimRateLimits($sourceToken, $deviceId);
        $invitation = UserInvitation::where('claim_token', $token)->first();
        if (! $invitation || $invitation->status !== 'PENDING') {
            $this->invalidOrExpired();
        }
        if ($invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'EXPIRED']);
            $this->invalidOrExpired();
        }
        if (User::whereRaw('lower(email) = lower(?)', [$invitation->email])->exists()) {
            $this->invalidOrExpired();
        }

        $organisation = Organisation::findOrFail($invitation->organisation_id);
        $userId = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($invitation, $organisation, $userId, $name, $password, $now, $correlationId) {
            // App\Models\User::$fillable does not include 'id' (a
            // deliberate mass-assignment boundary elsewhere in this app),
            // so User::create(['id' => ...]) would silently drop it and let
            // Eloquent's own HasUuids generate a different one -- the same
            // reason App\Services\Platform\PlatformChangeService::
            // provisionStaff already inserts via the query builder directly
            // rather than the Eloquent model. Matches that precedent here.
            DB::table('users')->insert([
                'id' => $userId, 'name' => $name, 'email' => $invitation->email, 'password' => Hash::make($password),
                'role' => $invitation->role_code, 'taxpayer_id' => $organisation->taxpayer_id, 'status' => 'ACTIVE',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $user = User::findOrFail($userId);
            OrganisationMembership::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $invitation->organisation_id, 'user_id' => $userId,
                'role_code' => $invitation->role_code, 'branch_id' => null, 'status' => 'ACTIVE',
                'valid_from' => $now, 'assigned_by' => $invitation->invited_by, 'created_at' => $now,
            ]);
            // Hardening beyond source: guards against two concurrent claims
            // of the same token racing past the PENDING check above -- run
            // after the user/membership rows exist (claimed_by_user_id is a
            // real foreign key) -- matching this session's own RT
            // punch-list precedent for affected-row guards on
            // non-idempotent state transitions. A raced loser's User/
            // OrganisationMembership rows are rolled back with it, since
            // this whole block is one transaction.
            $claimed = UserInvitation::where('id', $invitation->id)->where('status', 'PENDING')
                ->update(['status' => 'CLAIMED', 'claimed_at' => $now, 'claimed_by_user_id' => $userId]);
            if ($claimed === 0) {
                throw new RepositoryConflictException('This invitation has already been claimed.');
            }
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'IDENTITY', 'aggregate_id' => $userId,
                'event_type' => 'UserProvisioned', 'event_version' => 1, 'partition_key' => $organisation->taxpayer_id,
                'payload' => AuditService::canonicalJson([
                    'user_id' => $userId, 'organisation_id' => $invitation->organisation_id,
                    'invitation_id' => $invitation->id, 'correlation_id' => $correlationId,
                ]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            // Attributed to the newly created user itself, not the inviting
            // admin (already attributed on the earlier USER_INVITED event)
            // -- a genuine self-service claim, matching source exactly.
            AuditService::append($user, 'USER_PROVISIONED', 'APP_USER', $userId, [
                'organisationId' => $invitation->organisation_id, 'roleCode' => $invitation->role_code, 'invitationId' => $invitation->id,
            ], $now);
        });

        return ['userId' => $userId, 'organisationId' => $invitation->organisation_id, 'roleCode' => $invitation->role_code, 'status' => 'ACTIVE'];
    }

    /** Collapses every failure reason (not found, already claimed, expired, email already registered) into one generic message -- this is now a fully public, unauthenticated endpoint, so distinct error codes would let it be used as an enumeration oracle the same class of gap RT-005/RT-003 already fixed for password reset/login. */
    private function invalidOrExpired(): never
    {
        throw ValidationException::withMessages(['token' => 'This invitation link is invalid or has expired.']);
    }
}
