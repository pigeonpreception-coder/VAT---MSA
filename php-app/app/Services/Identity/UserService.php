<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityValidationException;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/identity-repository.ts's suspendUser/reactivateUser --
 * a standalone, reversible account lockout, distinct from
 * App\Services\OrganisationAdmin\OrganisationAdminService::terminateEmployee's
 * one-way offboarding (which also decrements a licence seat and terminates
 * the employee record) and from App\Services\Identity\TaxpayerService::
 * suspend's tenant-wide vat_status flip. This is the lighter action for
 * e.g. a security incident or a suspected-compromised account: "lock them
 * out now, decide later."
 *
 * Has a real, immediate effect in this port too: App\Providers\
 * AppServiceProvider's own 'permission' Gate already requires
 * User::isActive(), so a suspended user is denied on their very next
 * permission-gated request (which is every one of this app's protected
 * routes), and App\Http\Requests\Auth\LoginRequest already refuses a
 * suspended user's next login -- no new enforcement point was needed here,
 * only the command that flips the flag.
 */
class UserService
{
    /** @return array{userId: string, status: string} */
    public function suspend(User $actor, string $userId, array $payload, string $correlationId): array
    {
        $reason = $this->normalizeSuspension($payload);
        if ($actor->id === $userId) {
            throw new IdentityValidationException([
                ['code' => 'SELF_SUSPENSION_DENIED', 'path' => '/user_id', 'message' => 'You cannot suspend your own account.'],
            ]);
        }
        $targetUser = $this->requireUserInScope($actor, $userId);
        if ($targetUser->status === 'SUSPENDED') {
            return ['userId' => $userId, 'status' => 'SUSPENDED'];
        }

        $now = now();
        $previousStatus = $targetUser->status;
        DB::transaction(function () use ($targetUser, $reason, $actor, $now, $correlationId, $previousStatus, $userId) {
            $targetUser->update(['status' => 'SUSPENDED']);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'IDENTITY', 'aggregate_id' => $userId,
                'event_type' => 'UserSuspended', 'event_version' => 1, 'partition_key' => $targetUser->taxpayer_id ?? $userId,
                'payload' => AuditService::canonicalJson(['user_id' => $userId, 'reason' => $reason, 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'USER_SUSPENDED', 'APP_USER', $userId, ['reason' => $reason, 'previousStatus' => $previousStatus], $now);
        });

        return ['userId' => $userId, 'status' => 'SUSPENDED'];
    }

    /** @return array{userId: string, status: string} */
    public function reactivate(User $actor, string $userId, string $correlationId): array
    {
        $targetUser = $this->requireUserInScope($actor, $userId);
        if ($targetUser->status === 'ACTIVE') {
            return ['userId' => $userId, 'status' => 'ACTIVE'];
        }

        $now = now();
        $previousStatus = $targetUser->status;
        DB::transaction(function () use ($targetUser, $actor, $now, $correlationId, $previousStatus, $userId) {
            $targetUser->update(['status' => 'ACTIVE']);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'IDENTITY', 'aggregate_id' => $userId,
                'event_type' => 'UserReactivated', 'event_version' => 1, 'partition_key' => $targetUser->taxpayer_id ?? $userId,
                'payload' => AuditService::canonicalJson(['user_id' => $userId, 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'USER_REACTIVATED', 'APP_USER', $userId, ['previousStatus' => $previousStatus], $now);
        });

        return ['userId' => $userId, 'status' => 'ACTIVE'];
    }

    private function requireUserInScope(User $actor, string $userId): User
    {
        $target = User::find($userId);
        if (! $target) {
            throw new IdentityValidationException([
                ['code' => 'USER_NOT_FOUND', 'path' => '/user_id', 'message' => 'The user does not exist.'],
            ]);
        }
        if (! $actor->isNationalScope() && $actor->taxpayer_id !== $target->taxpayer_id) {
            throw new AuthorizationException('The requested user is outside your authorised taxpayer scope.');
        }

        return $target;
    }

    private function normalizeSuspension(array $payload): string
    {
        $reason = is_string($payload['reason'] ?? null) ? trim(preg_replace('/\s+/', ' ', $payload['reason'])) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new IdentityValidationException([
                ['code' => 'FIELD_LENGTH_INVALID', 'path' => '/reason', 'message' => 'Suspension reason must contain 5 to 240 characters.'],
            ]);
        }

        return $reason;
    }
}
