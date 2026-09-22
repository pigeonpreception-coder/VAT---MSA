<?php

namespace App\Services\Identity;

use App\Domain\Identity\IdentityLinkValidator;
use App\Exceptions\IdentityValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\IdentityLink;
use App\Models\IdentityProvider;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/identity-repository.ts's listIdentityLinks/
 * linkIdentity/revokeIdentityLink -- Module 1 ResolveIdentity/LinkIdentity/
 * RevokeSession. Per MODULE_DEVELOPMENT_PLAYBOOK.md's Identity Phase A
 * decision (quoted in source's own doc comment): there is no separate
 * session record in source's header-trust model, so "revoke a session"
 * there means revoke the identity_link -- `getCurrentUser()`'s own join
 * requires `identity_links.status='ACTIVE'`, so a revoked link stops
 * authenticating on its very next request there.
 *
 * **Honest, deliberate deviation**: this port replaced that header-trust
 * model entirely with real Laravel session/password authentication
 * (`App\Http\Requests\Auth\LoginRequest`'s own doc comment) -- Laravel's
 * auth guard never consults `identity_links` at all, so linking/revoking
 * one here has no equivalent effect on whether a user can actually log in
 * or stay logged in (that real enforcement lives in `users.status`, see
 * `App\Services\Identity\UserService::suspend()`). This command is kept as
 * the administrative bookkeeping/audit surface source's own `identity_links`
 * table still represents (which external identity providers is a user
 * linked to, and when was a link revoked) rather than silently dropped,
 * the same "honest, discoverable" posture this port has used for every
 * other command whose source-side real-world effect has no exact Laravel
 * analogue (e.g. `DeveloperConformanceEvaluator`'s own
 * `EXTERNAL_CREDENTIAL_PROVISIONED` check).
 */
class IdentityLinkService
{
    /** @return Collection<int, array<string, mixed>> */
    public function list(string $userId): Collection
    {
        return IdentityLink::with('provider')->where('user_id', $userId)->orderBy('linked_at')->get()->map(fn (IdentityLink $link) => $this->present($link));
    }

    /** @return array<string, mixed> */
    public function link(User $actor, array $payload, string $correlationId): array
    {
        $input = IdentityLinkValidator::link($payload);
        $targetUser = User::find($input['user_id']);
        if (! $targetUser) {
            throw new IdentityValidationException([['code' => 'USER_NOT_FOUND', 'path' => '/user_id', 'message' => 'The target user does not exist.']]);
        }
        if ($targetUser->status !== 'ACTIVE') {
            throw new IdentityValidationException([['code' => 'USER_NOT_ACTIVE', 'path' => '/user_id', 'message' => 'The target user is not active.']]);
        }
        if (! $actor->isNationalScope() && ($actor->taxpayer_id === null || $targetUser->taxpayer_id !== $actor->taxpayer_id)) {
            throw new AuthorizationException('You may only link an identity to a user within your own taxpayer organisation.');
        }
        $provider = IdentityProvider::where('provider_key', $input['provider_key'])->first();
        if (! $provider) {
            throw new IdentityValidationException([['code' => 'PROVIDER_NOT_FOUND', 'path' => '/provider_key', 'message' => 'The identity provider is not registered.']]);
        }
        if ($provider->status !== 'ACTIVE' || $provider->configuration_status !== 'CONFIGURED') {
            throw new IdentityValidationException([['code' => 'PROVIDER_NOT_CONFIGURED', 'path' => '/provider_key', 'message' => "{$input['provider_key']} is not yet configured for production linking ({$provider->configuration_status})."]]);
        }
        if (IdentityLink::where('provider_id', $provider->id)->where('subject', $input['subject'])->exists()) {
            throw new RepositoryConflictException('This provider subject is already linked to an identity.');
        }

        $now = now();
        $link = DB::transaction(function () use ($input, $targetUser, $provider, $actor, $now, $correlationId) {
            $link = IdentityLink::create([
                'user_id' => $targetUser->id, 'provider_id' => $provider->id, 'subject' => $input['subject'],
                'email_at_link' => null, 'assurance_level' => 'ADMINISTRATIVE_LINK', 'status' => 'ACTIVE',
                'linked_at' => $now, 'last_authenticated_at' => null,
            ]);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'IDENTITY', 'aggregate_id' => $link->id,
                'event_type' => 'IdentityLinked', 'event_version' => 1, 'partition_key' => $targetUser->id,
                'payload' => AuditService::canonicalJson([
                    'user_id' => $targetUser->id, 'provider_id' => $provider->id, 'provider_key' => $input['provider_key'],
                    'assurance' => 'ADMINISTRATIVE_LINK', 'occurred_at' => $now, 'correlation_id' => $correlationId,
                ]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'IDENTITY_LINKED', 'IDENTITY_LINK', $link->id, [
                'userId' => $targetUser->id, 'providerKey' => $input['provider_key'],
            ], $now);

            return $link;
        });

        return $this->present($link->refresh()->load('provider'));
    }

    /** @return array{id: string, status: string} */
    public function revoke(User $actor, string $identityLinkId, string $correlationId): array
    {
        $link = IdentityLink::with('user')->find($identityLinkId);
        if (! $link) {
            throw new IdentityValidationException([['code' => 'IDENTITY_LINK_NOT_FOUND', 'path' => '/identity_link_id', 'message' => 'The identity link does not exist.']]);
        }
        if (! $actor->isNationalScope() && ($actor->taxpayer_id === null || $link->user->taxpayer_id !== $actor->taxpayer_id)) {
            throw new AuthorizationException('You may only revoke an identity link belonging to a user within your own taxpayer organisation.');
        }
        if ($link->status === 'REVOKED') {
            return ['id' => $link->id, 'status' => 'REVOKED'];
        }

        $now = now();
        DB::transaction(function () use ($link, $actor, $now, $correlationId) {
            $link->update(['status' => 'REVOKED']);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'IDENTITY', 'aggregate_id' => $link->id,
                'event_type' => 'SessionRevoked', 'event_version' => 1, 'partition_key' => $link->user_id,
                'payload' => AuditService::canonicalJson([
                    'identity_link_id' => $link->id, 'user_id' => $link->user_id, 'correlation_id' => $correlationId,
                ]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'SESSION_REVOKED', 'IDENTITY_LINK', $link->id, ['userId' => $link->user_id], $now);
        });

        return ['id' => $link->id, 'status' => 'REVOKED'];
    }

    /** @return array<string, mixed> */
    private function present(IdentityLink $link): array
    {
        return [
            'id' => $link->id, 'provider_key' => $link->provider->provider_key, 'subject' => $link->subject,
            'assurance_level' => $link->assurance_level, 'status' => $link->status,
            'linked_at' => $link->linked_at?->toIso8601String(), 'last_authenticated_at' => $link->last_authenticated_at?->toIso8601String(),
        ];
    }
}
