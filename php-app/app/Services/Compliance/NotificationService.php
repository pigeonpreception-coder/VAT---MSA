<?php

namespace App\Services\Compliance;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/compliance-repository.ts's queueNotification/
 * cancelNotification/markNotificationRead/updateNotificationPreference/
 * getNotifications -- Module 6 Phase D, the standalone commands a caller
 * can reach directly (distinct from App\Support\Compliance\
 * NotificationRecorder, the shared side-effect five other commands in this
 * phase already trigger).
 */
class NotificationService
{
    /** Officer-only, matching every existing trigger of a notification in this codebase. @return array<string, mixed> */
    public function queue(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may queue a notification directly.');
        }
        $input = ComplianceValidator::notificationQueue($payload);
        if ($input['user_id']) {
            $user = User::where('id', $input['user_id'])->where('status', 'ACTIVE')->first();
            if (! $user) {
                throw new ComplianceResourceException('The target user was not found or is not active.', 404);
            }
        }
        if ($input['taxpayer_id']) {
            $taxpayer = Taxpayer::find($input['taxpayer_id']);
            if (! $taxpayer) {
                throw new ComplianceResourceException('The target taxpayer was not found.', 404);
            }
        }
        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'QUEUE_NOTIFICATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }

        $channelsToAttempt = $input['channels'];
        if ($input['user_id']) {
            $disabled = NotificationPreference::where('user_id', $input['user_id'])->where('enabled', false)->pluck('channel')->all();
            $channelsToAttempt = array_values(array_filter($input['channels'], fn ($c) => $c === 'IN_APP' || ! in_array($c, $disabled, true)));
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $channelsToAttempt, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            Notification::create([
                'id' => $id, 'user_id' => $input['user_id'], 'taxpayer_id' => $input['taxpayer_id'], 'notification_type' => $input['notification_type'],
                'title' => $input['title'], 'message' => $input['message'], 'severity' => $input['severity'], 'status' => 'UNREAD',
                'action_url' => $input['action_url'], 'created_at' => $now, 'read_at' => null, 'cancelled_by' => null, 'cancelled_at' => null, 'cancellation_reason' => null,
            ]);
            foreach ($channelsToAttempt as $channel) {
                NotificationDelivery::create(['id' => (string) Str::uuid(), 'notification_id' => $id, 'channel' => $channel, 'status' => 'QUEUED', 'attempted_at' => $now]);
            }
            CommandLedger::record($actor->id, 'QUEUE_NOTIFICATION', $idempotencyKey, $requestHash, 'NOTIFICATION', $id, $now);
            CommandLedger::outbox('NOTIFICATION', $id, 'NotificationQueued', $input['taxpayer_id'] ?? 'SYSTEM', ['notification_id' => $id, 'channels' => $channelsToAttempt, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'NOTIFICATION_QUEUED', 'NOTIFICATION', $id, ['userId' => $input['user_id'], 'taxpayerId' => $input['taxpayer_id'], 'channels' => $channelsToAttempt, 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($id));
    }

    /** Withdraws a still-UNREAD notification. Reachable by the actor who could see it in the first place. @return array<string, mixed> */
    public function cancel(string $notificationId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ComplianceValidator::notificationCancellation($payload);
        $notification = Notification::find($notificationId);
        if (! $notification) {
            throw new ComplianceResourceException('Notification was not found.', 404);
        }
        $this->requireNotificationScope($actor, $notification);
        $requestHash = CommandLedger::requestHash(['notification_id' => $notificationId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CANCEL_NOTIFICATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }
        if ($notification->status !== 'UNREAD') {
            throw new RepositoryConflictException('Only an unread notification can be cancelled.');
        }

        $now = now();
        DB::transaction(function () use ($notification, $notificationId, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
            Notification::where('id', $notificationId)->where('status', 'UNREAD')->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancelled_at' => $now, 'cancellation_reason' => $input['reason']]);
            CommandLedger::record($actor->id, 'CANCEL_NOTIFICATION', $idempotencyKey, $requestHash, 'NOTIFICATION', $notificationId, $now);
            CommandLedger::outbox('NOTIFICATION', $notificationId, 'NotificationCancelled', $notification->taxpayer_id ?? 'SYSTEM', ['notification_id' => $notificationId, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'NOTIFICATION_CANCELLED', 'NOTIFICATION', $notificationId, ['reason' => $input['reason'], 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($notificationId));
    }

    /** Re-marking an already-read notification is a harmless no-op, the same idempotent-on-already-satisfied posture ObligationService::markSatisfied established. @return array<string, mixed> */
    public function markRead(string $notificationId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $notification = Notification::find($notificationId);
        if (! $notification) {
            throw new ComplianceResourceException('Notification was not found.', 404);
        }
        $this->requireNotificationScope($actor, $notification);
        $requestHash = CommandLedger::requestHash(['notification_id' => $notificationId]);
        $prior = CommandLedger::prior($actor->id, 'MARK_NOTIFICATION_READ', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }
        if ($notification->status === 'CANCELLED') {
            throw new RepositoryConflictException('A cancelled notification cannot be marked read.');
        }

        $now = now();
        DB::transaction(function () use ($notification, $notificationId, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            Notification::where('id', $notificationId)->where('status', 'UNREAD')->update(['status' => 'READ', 'read_at' => $notification->read_at ?? $now]);
            CommandLedger::record($actor->id, 'MARK_NOTIFICATION_READ', $idempotencyKey, $requestHash, 'NOTIFICATION', $notificationId, $now);
            AuditService::append($actor, 'NOTIFICATION_READ', 'NOTIFICATION', $notificationId, ['correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($notificationId));
    }

    /** Self-service: every actor manages only their own row (keyed by actor's own id, never a caller-supplied user id). @return array<string, mixed> */
    public function updatePreference(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ComplianceValidator::notificationPreference($payload);
        $requestHash = CommandLedger::requestHash(['user_id' => $actor->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'UPDATE_NOTIFICATION_PREFERENCE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentPreference($this->findPreferenceOrFail($actor->id, $input['channel']));
        }

        $now = now();
        DB::transaction(function () use ($input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $actor->id, 'channel' => $input['channel']],
                ['enabled' => $input['enabled'], 'updated_at' => $now],
            );
            CommandLedger::record($actor->id, 'UPDATE_NOTIFICATION_PREFERENCE', $idempotencyKey, $requestHash, 'NOTIFICATION_PREFERENCE', $actor->id, $now);
            AuditService::append($actor, 'NOTIFICATION_PREFERENCE_UPDATED', 'NOTIFICATION_PREFERENCE', $actor->id, ['channel' => $input['channel'], 'enabled' => $input['enabled'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentPreference($this->findPreferenceOrFail($actor->id, $input['channel']));
    }

    /** A dedicated, filterable, paginated read of the current actor's own notifications. @return array<string, mixed> */
    public function list(User $actor, array $params): array
    {
        $query = ComplianceValidator::notificationQuery($params);
        $builder = Notification::query();
        if (! TenantScope::isNational($actor)) {
            $taxpayerId = $actor->taxpayer_id ?? '__none__';
            $builder->where(function ($q) use ($actor, $taxpayerId) {
                $q->where('user_id', $actor->id)->orWhere('taxpayer_id', $taxpayerId);
            });
        }
        if ($query['status']) {
            $builder->where('status', $query['status']);
        }
        if ($query['severity']) {
            $builder->where('severity', $query['severity']);
        }
        $totalCount = (clone $builder)->count();
        $notifications = $builder->orderByDesc('created_at')->limit($query['limit'])->offset($query['offset'])->get();

        return ['notifications' => $notifications->map(fn (Notification $n) => $this->present($n))->values()->all(), 'total_count' => $totalCount, 'limit' => $query['limit'], 'offset' => $query['offset']];
    }

    // -- internals --

    private function requireNotificationScope(User $actor, Notification $notification): void
    {
        if (TenantScope::isNational($actor)) {
            return;
        }
        if ($actor->id === $notification->user_id) {
            return;
        }
        if ($notification->taxpayer_id && $actor->taxpayer_id === $notification->taxpayer_id) {
            return;
        }
        throw new AuthorizationException('The notification is outside your authorised scope.');
    }

    private function findOrFail(string $id): Notification
    {
        $notification = Notification::find($id);
        if (! $notification) {
            throw new ComplianceResourceException('Notification was not found.', 404);
        }

        return $notification;
    }

    /** @return array<string, mixed> */
    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id, 'user_id' => $notification->user_id, 'taxpayer_id' => $notification->taxpayer_id,
            'notification_type' => $notification->notification_type, 'title' => $notification->title, 'message' => $notification->message,
            'severity' => $notification->severity, 'status' => $notification->status, 'action_url' => $notification->action_url,
            'created_at' => optional($notification->created_at)->toISOString(), 'read_at' => optional($notification->read_at)->toISOString(),
            'cancelled_by' => $notification->cancelled_by, 'cancelled_at' => optional($notification->cancelled_at)->toISOString(), 'cancellation_reason' => $notification->cancellation_reason,
        ];
    }

    private function findPreferenceOrFail(string $userId, string $channel): NotificationPreference
    {
        $preference = NotificationPreference::where('user_id', $userId)->where('channel', $channel)->first();
        if (! $preference) {
            throw new ComplianceResourceException('Notification preference was not found.', 404);
        }

        return $preference;
    }

    /** @return array<string, mixed> */
    private function presentPreference(NotificationPreference $preference): array
    {
        return [
            'id' => $preference->id, 'user_id' => $preference->user_id, 'channel' => $preference->channel,
            'enabled' => (bool) $preference->enabled, 'updated_at' => optional($preference->updated_at)->toISOString(),
        ];
    }
}
