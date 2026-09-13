<?php

namespace App\Services\Platform;

use App\Domain\Platform\PlatformChangeValidator;
use App\Exceptions\PlatformResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/platform-repository.ts's getPlatformConfig/
 * listPlatformChangeRequests/requestPlatformChange/decidePlatformChange/
 * provisionPlatformStaff -- Module 8 Phase A, the sixth and final slice
 * of Phase 13, closing out `platform-repository.ts` entirely. Still
 * outstanding: Phases 14-15 in full (the legacy D1 importer and
 * deployment documentation).
 *
 * A 2026-08-26 audit (documented in the source) found zero code anywhere
 * for FeatureFlag/PlatformConfig/AccessPolicy/ChangeRequest, despite an
 * architecture matrix's "VERIFIED FOUNDATION" label on this domain row.
 * Definitions (which flags/config keys/policies exist) are seed-only, the
 * same posture already established for `report_definitions` (Phase C)
 * and `data_products`/`metrics` (Phase D): deciding a new governed knob
 * should exist at all is a deploy-time/governance action, out of scope
 * for a runtime command. Only the VALUE of an existing definition is
 * runtime-changeable, and only through a real maker-checker gate:
 * `requestChange()` stages a proposed value as a `PENDING` `change_requests`
 * row (capturing a snapshot of the previous value so the diff is always
 * reconstructable); `decideChange()` applies or rejects it, refusing
 * self-decision the same way every other maker-checker command in this
 * codebase does. Three seeded config values now feed back into a real
 * consumer via App\Support\Platform\PlatformConfigReader --
 * `reports.export_size_limit_bytes`/`reports.min_cell_suppression_threshold`
 * (both read by `ReportExportService`) and the `STEP_UP_WINDOW` access
 * policy's own `window_seconds` (read by `App\Support\Access\StepUp`) --
 * so changing one of these three rows through `decideChange()` now has a
 * real, observable effect, not just a documentary one. Every other seeded
 * row (every `feature_flags` row, every other `platform_config`/
 * `access_policies` row) remains illustrative only; wiring each one to its
 * own consumer is left for whenever that consumer actually needs it,
 * exactly as before.
 *
 * No Eloquent model for any of the four tables this service touches
 * (`feature_flags`/`platform_config`/`access_policies`/
 * `change_requests`) -- `DB::table()` throughout, matching every other
 * Phase 13 slice. `provisionStaff()` is the one exception: it writes
 * directly to `users`/`identity_links` (both of which do have models
 * elsewhere), via `DB::table()` here too, matching the same "one
 * mixed-table transaction, not a mixed Eloquent/DB::table() write"
 * posture `ReportExportService::requestExport()` already established.
 */
class PlatformChangeService
{
    /** @return array{feature_flags: list<array<string, mixed>>, platform_config: list<array<string, mixed>>, access_policies: list<array<string, mixed>>} */
    public function config(): array
    {
        $flags = DB::table('feature_flags')->where('status', 'ACTIVE')->orderBy('key')
            ->select('id', 'key', 'name', 'description', 'rollout_scope', 'enabled', 'version', 'updated_at')->get();
        $config = DB::table('platform_config')->where('status', 'ACTIVE')->orderBy('category')->orderBy('key')
            ->select('id', 'key', 'category', 'description', 'value', 'version', 'updated_at')->get();
        $policies = DB::table('access_policies')->where('status', 'ACTIVE')->orderBy('policy_type')->orderBy('code')
            ->select('id', 'code', 'name', 'policy_type', 'description', 'parameters', 'version', 'updated_at')->get();

        return [
            'feature_flags' => $flags->map(fn ($row) => [
                'id' => $row->id, 'key' => $row->key, 'name' => $row->name, 'description' => $row->description,
                'rollout_scope' => $row->rollout_scope, 'enabled' => (bool) $row->enabled, 'version' => (int) $row->version, 'updated_at' => $row->updated_at,
            ])->values()->all(),
            'platform_config' => $config->map(fn ($row) => [
                'id' => $row->id, 'key' => $row->key, 'category' => $row->category, 'description' => $row->description,
                'value' => $row->value, 'version' => (int) $row->version, 'updated_at' => $row->updated_at,
            ])->values()->all(),
            'access_policies' => $policies->map(fn ($row) => [
                'id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'policy_type' => $row->policy_type,
                'description' => $row->description, 'parameters' => json_decode($row->parameters, true), 'version' => (int) $row->version, 'updated_at' => $row->updated_at,
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listChangeRequests(?string $status): array
    {
        $query = DB::table('change_requests');
        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('requested_at')->limit(200)->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    /** @return array<string, mixed> */
    public function requestChange(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = PlatformChangeValidator::requestChange($payload);
        $requestHash = CommandLedger::requestHash(['input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'REQUEST_PLATFORM_CHANGE', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('change_requests')->where('id', $prior)->first();
        }

        $previousValue = $this->loadTarget($input['target_type'], $input['target_id']);
        $proposedValue = $this->validateShape($input['target_type'], $input['proposed_value']);

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($id, $input, $previousValue, $proposedValue, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            DB::table('change_requests')->insert([
                'id' => $id, 'target_type' => $input['target_type'], 'target_id' => $input['target_id'],
                'previous_value' => json_encode($previousValue), 'proposed_value' => json_encode($proposedValue),
                'reason' => $input['reason'], 'status' => 'PENDING', 'requested_by' => $actor->id, 'requested_at' => $now,
                'decided_by' => null, 'decided_at' => null, 'decision_notes' => null,
            ]);
            CommandLedger::record($actor->id, 'REQUEST_PLATFORM_CHANGE', $idempotencyKey, $requestHash, 'CHANGE_REQUEST', $id, $now);
            CommandLedger::outbox('CHANGE_REQUEST', $id, 'PlatformChangeRequested', $id, [
                'change_request_id' => $id, 'target_type' => $input['target_type'], 'target_id' => $input['target_id'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'PLATFORM_CHANGE_REQUESTED', 'CHANGE_REQUEST', $id, [
                'targetType' => $input['target_type'], 'targetId' => $input['target_id'], 'reason' => $input['reason'], 'correlationId' => $correlationId,
            ], $now);
        });

        return (array) DB::table('change_requests')->where('id', $id)->first();
    }

    /** @return array<string, mixed> */
    public function decideChange(string $changeRequestId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = PlatformChangeValidator::decideChange($payload);
        $requestHash = CommandLedger::requestHash(['change_request_id' => $changeRequestId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'DECIDE_PLATFORM_CHANGE', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('change_requests')->where('id', $prior)->first();
        }

        $row = DB::table('change_requests')->where('id', $changeRequestId)
            ->select('id', 'target_type', 'target_id', 'proposed_value', 'status', 'requested_by')->first();
        if (! $row) {
            throw new PlatformResourceException('Change request was not found.', 404);
        }
        if ($row->status !== 'PENDING') {
            throw new RepositoryConflictException('Only a pending change request can be decided.');
        }
        if ($row->requested_by === $actor->id) {
            throw new AuthorizationException('You may not decide a platform change request you submitted yourself.');
        }

        $now = now();
        $newStatus = $input['decision'] === 'APPROVE' ? 'APPLIED' : 'REJECTED';
        DB::transaction(function () use ($changeRequestId, $row, $input, $newStatus, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            DB::table('change_requests')->where('id', $changeRequestId)->where('status', 'PENDING')
                ->update(['status' => $newStatus, 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_notes' => $input['notes']]);
            if ($input['decision'] === 'APPROVE') {
                $proposed = json_decode($row->proposed_value, true) ?? [];
                if ($row->target_type === 'FEATURE_FLAG') {
                    DB::table('feature_flags')->where('id', $row->target_id)->where('status', 'ACTIVE')->update([
                        'enabled' => (bool) ($proposed['enabled'] ?? false), 'version' => DB::raw('version + 1'),
                        'updated_by' => $actor->id, 'updated_at' => $now,
                    ]);
                } elseif ($row->target_type === 'PLATFORM_CONFIG') {
                    DB::table('platform_config')->where('id', $row->target_id)->where('status', 'ACTIVE')->update([
                        'value' => (string) ($proposed['value'] ?? ''), 'version' => DB::raw('version + 1'),
                        'updated_by' => $actor->id, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('access_policies')->where('id', $row->target_id)->where('status', 'ACTIVE')->update([
                        'parameters' => json_encode($proposed['parameters'] ?? []), 'version' => DB::raw('version + 1'),
                        'updated_by' => $actor->id, 'updated_at' => $now,
                    ]);
                }
            }
            CommandLedger::record($actor->id, 'DECIDE_PLATFORM_CHANGE', $idempotencyKey, $requestHash, 'CHANGE_REQUEST', $changeRequestId, $now);
            CommandLedger::outbox('CHANGE_REQUEST', $changeRequestId, $input['decision'] === 'APPROVE' ? 'PlatformChangeApplied' : 'PlatformChangeRejected', $changeRequestId, [
                'change_request_id' => $changeRequestId, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, "PLATFORM_CHANGE_{$newStatus}", 'CHANGE_REQUEST', $changeRequestId, [
                'targetType' => $row->target_type, 'targetId' => $row->target_id, 'notes' => $input['notes'], 'correlationId' => $correlationId,
            ], $now);
        });

        return (array) DB::table('change_requests')->where('id', $changeRequestId)->first();
    }

    /**
     * Module 8 Phase A ProvisionStaff: creates a platform/NamRA staff
     * account -- no organisation, a national-scope role from
     * `PlatformChangeValidator::PLATFORM_STAFF_ROLES`. The only prior way
     * one of these accounts came into existence was a hardcoded demo-seed
     * row; this is a genuinely new capability.
     *
     * `users.password` is `NOT NULL` in this schema (see the identity-core
     * migration's own doc comment: local Laravel auth must work
     * independently of `identity_links`), but the source's own account is
     * federated-identity-only -- it never has a local password at all. A
     * random, nobody-knows-it hash satisfies the column without granting
     * any real local-login capability; pairing this with a real
     * password-reset/invite flow is a documented follow-up, not silently
     * dropped.
     *
     * @return array<string, mixed>
     */
    public function provisionStaff(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = PlatformChangeValidator::provisionStaff($payload);
        $requestHash = CommandLedger::requestHash(['input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'PROVISION_PLATFORM_STAFF', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('users')->where('id', $prior)
                ->select('id', 'external_user_id', 'email', 'name as display_name', 'role', 'status', 'created_at')->first();
        }

        $duplicate = DB::table('users')->where('external_user_id', $input['external_user_id'])
            ->orWhereRaw('LOWER(email) = ?', [$input['email']])->select('id')->first();
        if ($duplicate) {
            throw new RepositoryConflictException('A platform staff account with this identity or email already exists.');
        }
        $provider = DB::table('identity_providers')->where('provider_key', 'SITES_WORKSPACE')
            ->where('status', 'ACTIVE')->where('configuration_status', 'CONFIGURED')->select('id')->first();
        if (! $provider) {
            throw new PlatformResourceException('The platform identity provider is not configured.', 500);
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($id, $input, $provider, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            DB::table('users')->insert([
                'id' => $id, 'external_user_id' => $input['external_user_id'], 'name' => $input['display_name'], 'email' => $input['email'],
                'email_verified_at' => null, 'password' => Hash::make(Str::random(40)), 'role' => $input['role'], 'taxpayer_id' => null,
                'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('identity_links')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $id, 'provider_id' => $provider->id, 'subject' => $input['external_user_id'],
                'email_at_link' => $input['email'], 'assurance_level' => 'PLATFORM_AUTHENTICATED', 'status' => 'ACTIVE',
                'linked_at' => $now, 'last_authenticated_at' => null,
            ]);
            CommandLedger::record($actor->id, 'PROVISION_PLATFORM_STAFF', $idempotencyKey, $requestHash, 'APP_USER', $id, $now);
            CommandLedger::outbox('APP_USER', $id, 'PlatformStaffProvisioned', $id, ['user_id' => $id, 'role' => $input['role'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PLATFORM_STAFF_PROVISIONED', 'APP_USER', $id, ['role' => $input['role'], 'email' => $input['email'], 'correlationId' => $correlationId], $now);
        });

        return [
            'id' => $id, 'external_user_id' => $input['external_user_id'], 'email' => $input['email'],
            'display_name' => $input['display_name'], 'role' => $input['role'], 'status' => 'ACTIVE', 'created_at' => $now->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function loadTarget(string $targetType, string $targetId): array
    {
        if ($targetType === 'FEATURE_FLAG') {
            $row = DB::table('feature_flags')->where('id', $targetId)->where('status', 'ACTIVE')->select('enabled')->first();
            if (! $row) {
                throw new PlatformResourceException('Feature flag was not found.', 404);
            }

            return ['enabled' => (bool) $row->enabled];
        }
        if ($targetType === 'PLATFORM_CONFIG') {
            $row = DB::table('platform_config')->where('id', $targetId)->where('status', 'ACTIVE')->select('value')->first();
            if (! $row) {
                throw new PlatformResourceException('Platform config entry was not found.', 404);
            }

            return ['value' => $row->value];
        }
        $row = DB::table('access_policies')->where('id', $targetId)->where('status', 'ACTIVE')->select('parameters')->first();
        if (! $row) {
            throw new PlatformResourceException('Access policy was not found.', 404);
        }

        return ['parameters' => json_decode($row->parameters, true) ?? []];
    }

    /** @return array<string, mixed> */
    private function validateShape(string $targetType, array $proposedValue): array
    {
        if ($targetType === 'FEATURE_FLAG') {
            if (! is_bool($proposedValue['enabled'] ?? null)) {
                throw new PlatformResourceException('proposed_value.enabled must be a boolean for a feature flag change.');
            }

            return ['enabled' => $proposedValue['enabled']];
        }
        if ($targetType === 'PLATFORM_CONFIG') {
            if (! array_key_exists('value', $proposedValue)) {
                throw new PlatformResourceException('proposed_value.value is required for a platform config change.');
            }

            return ['value' => $proposedValue['value']];
        }
        $parameters = $proposedValue['parameters'] ?? null;
        if (! is_array($parameters)) {
            throw new PlatformResourceException('proposed_value.parameters must be an object for an access policy change.');
        }

        return ['parameters' => $parameters];
    }
}
