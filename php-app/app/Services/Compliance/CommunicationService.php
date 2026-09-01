<?php

namespace App\Services\Compliance;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\AuditCase;
use App\Models\Communication;
use App\Models\CommunicationThread;
use App\Models\ReconciliationException;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Compliance\NotificationRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/compliance-repository.ts's sendNotice/
 * respondToConversation/closeConversation/getInbox/getConversation --
 * Module 6 Phase C, the second Phase 11 slice. `REFUND_CLAIM` as a case
 * reference type is deliberately not yet resolvable -- refund_claims has
 * not been migrated (see docs/MIGRATION_MATRIX.md's refunds gap);
 * `AUDIT_CASE` and `RECONCILIATION_EXCEPTION` are fully supported.
 */
class CommunicationService
{
    /** Officer-only, mirroring AuditCaseService::open's own restriction. @return array<string, mixed> */
    public function sendNotice(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may send a notice.');
        }
        $input = ComplianceValidator::notice($payload);
        $reference = $this->resolveCaseReference($input['related_resource_type'], $input['related_resource_id']);
        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'SEND_NOTICE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentThread($this->findThreadOrFail($prior));
        }
        $existingThread = CommunicationThread::where('related_resource_type', $input['related_resource_type'])->where('related_resource_id', $input['related_resource_id'])->first();
        if ($existingThread) {
            throw new RepositoryConflictException('A correspondence thread already exists for this case reference; use Respond instead.');
        }

        $threadId = (string) Str::uuid();
        $messageId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $reference, $actor, $threadId, $messageId, $now, $idempotencyKey, $requestHash, $correlationId) {
            CommunicationThread::create([
                'id' => $threadId, 'organisation_id' => $reference['organisation_id'], 'taxpayer_id' => $reference['taxpayer_id'],
                'related_resource_type' => $input['related_resource_type'], 'related_resource_id' => $input['related_resource_id'],
                'subject' => $input['subject'], 'classification' => $input['classification'], 'status' => 'OPEN',
                'opened_by' => $actor->id, 'opened_at' => $now, 'closed_by' => null, 'closed_at' => null, 'closure_reason' => null,
            ]);
            Communication::create([
                'id' => $messageId, 'organisation_id' => $reference['organisation_id'], 'taxpayer_id' => $reference['taxpayer_id'], 'thread_id' => $threadId,
                'channel' => $input['channel'], 'direction' => 'OUTBOUND', 'subject' => $input['subject'], 'content_summary' => $input['content_summary'],
                'classification' => $input['classification'], 'related_resource_type' => $input['related_resource_type'], 'related_resource_id' => $input['related_resource_id'],
                'external_reference' => null, 'status' => 'DELIVERED', 'actor_id' => $actor->id, 'occurred_at' => $now,
            ]);
            NotificationRecorder::record(null, $reference['taxpayer_id'], 'NOTICE_RECEIVED', $input['subject'], $input['content_summary'], 'MEDIUM', "/communications/{$threadId}", $now);
            CommandLedger::record($actor->id, 'SEND_NOTICE', $idempotencyKey, $requestHash, 'COMMUNICATION_THREAD', $threadId, $now);
            CommandLedger::outbox('COMMUNICATION_THREAD', $threadId, 'NoticeSent', $reference['taxpayer_id'], ['thread_id' => $threadId, 'message_id' => $messageId, 'related_resource_type' => $input['related_resource_type'], 'related_resource_id' => $input['related_resource_id'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'NOTICE_SENT', 'COMMUNICATION_THREAD', $threadId, ['taxpayerId' => $reference['taxpayer_id'], 'relatedResourceType' => $input['related_resource_type'], 'relatedResourceId' => $input['related_resource_id'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentThread($this->findThreadOrFail($threadId));
    }

    /**
     * Reachable by either the NamRA side (communications:manage) or the
     * taxpayer side (communications:respond, scoped to their own
     * taxpayer). Direction is derived from the actor, never caller-supplied.
     *
     * @return array<string, mixed>
     */
    public function respond(string $threadId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! $actor->hasAppPermission('communications:manage') && ! $actor->hasAppPermission('communications:respond')) {
            throw new AuthorizationException('You do not have permission to respond to this correspondence.');
        }
        $input = ComplianceValidator::conversationResponse($payload);
        $thread = CommunicationThread::find($threadId);
        if (! $thread) {
            throw new ComplianceResourceException('Correspondence thread was not found.', 404);
        }
        if (! TenantScope::isNational($actor) && $actor->taxpayer_id !== $thread->taxpayer_id) {
            throw new AuthorizationException('The correspondence thread is outside your authorised taxpayer scope.');
        }
        $requestHash = CommandLedger::requestHash(['thread_id' => $threadId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'RESPOND_TO_CONVERSATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentMessage($this->findMessageOrFail($prior));
        }
        if ($thread->status !== 'OPEN') {
            throw new RepositoryConflictException('This correspondence thread is closed and cannot accept a new reply.');
        }

        $direction = TenantScope::isNational($actor) ? 'OUTBOUND' : 'INBOUND';
        $messageId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $thread, $threadId, $direction, $actor, $messageId, $now, $idempotencyKey, $requestHash, $correlationId) {
            Communication::create([
                'id' => $messageId, 'organisation_id' => $thread->organisation_id, 'taxpayer_id' => $thread->taxpayer_id, 'thread_id' => $threadId,
                'channel' => $input['channel'], 'direction' => $direction, 'subject' => $thread->subject, 'content_summary' => $input['content_summary'],
                'classification' => $thread->classification, 'related_resource_type' => $thread->related_resource_type, 'related_resource_id' => $thread->related_resource_id,
                'external_reference' => null, 'status' => 'DELIVERED', 'actor_id' => $actor->id, 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'RESPOND_TO_CONVERSATION', $idempotencyKey, $requestHash, 'COMMUNICATION', $messageId, $now);
            CommandLedger::outbox('COMMUNICATION_THREAD', $threadId, 'ConversationResponded', $thread->taxpayer_id, ['thread_id' => $threadId, 'message_id' => $messageId, 'direction' => $direction, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'CONVERSATION_RESPONDED', 'COMMUNICATION_THREAD', $threadId, ['taxpayerId' => $thread->taxpayer_id, 'direction' => $direction, 'correlationId' => $correlationId], $now);
            if ($direction === 'OUTBOUND') {
                NotificationRecorder::record(null, $thread->taxpayer_id, 'NOTICE_RECEIVED', "Reply on: {$thread->subject}", $input['content_summary'], 'MEDIUM', "/communications/{$threadId}", $now);
            }
        });

        return $this->presentMessage($this->findMessageOrFail($messageId));
    }

    /** Officer-only, mirroring sendNotice's own restriction. @return array<string, mixed> */
    public function close(string $threadId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may close a correspondence thread.');
        }
        $input = ComplianceValidator::conversationClosure($payload);
        $thread = CommunicationThread::find($threadId);
        if (! $thread) {
            throw new ComplianceResourceException('Correspondence thread was not found.', 404);
        }
        $requestHash = CommandLedger::requestHash(['thread_id' => $threadId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CLOSE_CONVERSATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentThread($this->findThreadOrFail($prior));
        }
        if ($thread->status !== 'OPEN') {
            throw new RepositoryConflictException('This correspondence thread is already closed.');
        }

        $now = now();
        DB::transaction(function () use ($thread, $threadId, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
            CommunicationThread::where('id', $threadId)->where('status', 'OPEN')->update(['status' => 'CLOSED', 'closed_by' => $actor->id, 'closed_at' => $now, 'closure_reason' => $input['reason']]);
            CommandLedger::record($actor->id, 'CLOSE_CONVERSATION', $idempotencyKey, $requestHash, 'COMMUNICATION_THREAD', $threadId, $now);
            CommandLedger::outbox('COMMUNICATION_THREAD', $threadId, 'ConversationClosed', $thread->taxpayer_id, ['thread_id' => $threadId, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'CONVERSATION_CLOSED', 'COMMUNICATION_THREAD', $threadId, ['taxpayerId' => $thread->taxpayer_id, 'reason' => $input['reason'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentThread($this->findThreadOrFail($threadId));
    }

    /** Lists correspondence threads (not raw messages), each with its latest message preview and message count. @return array<string, mixed> */
    public function inbox(User $actor, array $params): array
    {
        $query = ComplianceValidator::inboxQuery($params);
        $builder = CommunicationThread::query();
        if (! TenantScope::isNational($actor)) {
            $builder->where('taxpayer_id', $actor->taxpayer_id ?? '__none__');
        } elseif ($query['taxpayerId']) {
            $builder->where('taxpayer_id', $query['taxpayerId']);
        }
        if ($query['status']) {
            $builder->where('status', $query['status']);
        }
        if ($query['relatedResourceType']) {
            $builder->where('related_resource_type', $query['relatedResourceType']);
        }
        $totalCount = (clone $builder)->count();
        $threads = $builder->with(['messages' => fn ($q) => $q->orderByDesc('occurred_at')->limit(1)])
            ->get()
            ->map(function (CommunicationThread $t) {
                $latest = Communication::where('thread_id', $t->id)->orderByDesc('occurred_at')->first();
                $count = Communication::where('thread_id', $t->id)->count();

                return array_merge($this->presentThread($t), [
                    'latest_message' => $latest?->content_summary, 'latest_message_at' => optional($latest?->occurred_at)->toISOString(), 'message_count' => $count,
                ]);
            })
            ->sortByDesc('latest_message_at')->slice($query['offset'], $query['limit'])->values()->all();

        return ['threads' => $threads, 'total_count' => $totalCount, 'limit' => $query['limit'], 'offset' => $query['offset']];
    }

    /** Reads one full correspondence thread, oldest message first. Same tenant-visibility rule as AuditCaseService::timeline. @return ?array<string, mixed> */
    public function conversation(string $threadId, User $actor): ?array
    {
        $thread = CommunicationThread::find($threadId);
        if (! $thread) {
            return null;
        }
        if (! TenantScope::isNational($actor) && $actor->taxpayer_id !== $thread->taxpayer_id) {
            throw new AuthorizationException('The correspondence thread is outside your authorised taxpayer scope.');
        }
        $messages = Communication::where('thread_id', $threadId)->orderBy('occurred_at')->get();

        return ['thread' => $this->presentThread($thread), 'messages' => $messages->map(fn (Communication $m) => $this->presentMessage($m))->values()->all()];
    }

    // -- internals --

    /** @return array{taxpayer_id: string, organisation_id: ?string} */
    private function resolveCaseReference(string $type, string $resourceId): array
    {
        if ($type === 'AUDIT_CASE') {
            $case = AuditCase::find($resourceId);
            if (! $case) {
                throw new ComplianceResourceException('The referenced audit case was not found.', 404);
            }

            return ['taxpayer_id' => $case->taxpayer_id, 'organisation_id' => $case->organisation_id];
        }
        if ($type === 'RECONCILIATION_EXCEPTION') {
            $exception = ReconciliationException::find($resourceId);
            if (! $exception || ! $exception->taxpayer_id) {
                throw new ComplianceResourceException('The referenced reconciliation exception was not found.', 404);
            }

            return ['taxpayer_id' => $exception->taxpayer_id, 'organisation_id' => null];
        }
        throw new ComplianceResourceException('Notices referencing REFUND_CLAIM are not yet supported by this migration -- the refund_claims table has not been ported. Reference an AUDIT_CASE or a RECONCILIATION_EXCEPTION instead.', 422);
    }

    private function findThreadOrFail(string $id): CommunicationThread
    {
        $thread = CommunicationThread::find($id);
        if (! $thread) {
            throw new ComplianceResourceException('Correspondence thread was not found.', 404);
        }

        return $thread;
    }

    /** @return array<string, mixed> */
    private function presentThread(CommunicationThread $thread): array
    {
        return [
            'id' => $thread->id, 'organisation_id' => $thread->organisation_id, 'taxpayer_id' => $thread->taxpayer_id,
            'related_resource_type' => $thread->related_resource_type, 'related_resource_id' => $thread->related_resource_id,
            'subject' => $thread->subject, 'classification' => $thread->classification, 'status' => $thread->status,
            'opened_by' => $thread->opened_by, 'opened_at' => optional($thread->opened_at)->toISOString(),
            'closed_by' => $thread->closed_by, 'closed_at' => optional($thread->closed_at)->toISOString(), 'closure_reason' => $thread->closure_reason,
        ];
    }

    private function findMessageOrFail(string $id): Communication
    {
        $message = Communication::find($id);
        if (! $message) {
            throw new ComplianceResourceException('Correspondence message was not found.', 404);
        }

        return $message;
    }

    /** @return array<string, mixed> */
    private function presentMessage(Communication $message): array
    {
        return [
            'id' => $message->id, 'organisation_id' => $message->organisation_id, 'taxpayer_id' => $message->taxpayer_id, 'thread_id' => $message->thread_id,
            'channel' => $message->channel, 'direction' => $message->direction, 'subject' => $message->subject, 'content_summary' => $message->content_summary,
            'classification' => $message->classification, 'related_resource_type' => $message->related_resource_type, 'related_resource_id' => $message->related_resource_id,
            'external_reference' => $message->external_reference, 'status' => $message->status, 'actor_id' => $message->actor_id,
            'occurred_at' => optional($message->occurred_at)->toISOString(),
        ];
    }
}
