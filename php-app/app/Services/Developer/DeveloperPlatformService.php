<?php

namespace App\Services\Developer;

use App\Domain\Developer\DeveloperCommandValidator;
use App\Domain\Developer\DeveloperConformanceEvaluator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\ApiClient;
use App\Models\CredentialRef;
use App\Models\DeveloperAccount;
use App\Models\Organisation;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/developer-repository.ts's createClient/
 * rotateCredential/revokeCredential/runConformance -- Module 10 Phase D.
 *
 * Source gates all four commands on `developer:manage` (operationClass
 * BUSINESS_WRITE, not COMPLIANCE_WRITE -- no `step-up` requirement here,
 * unlike Reconciliation's Assign/Resolve), distinct from this port's own
 * App\Services\Integration\PosApiClientService (a prior, user-requested
 * feature reaching these same `api_clients`/`credential_refs` tables for
 * POS invoice-submission credentials specifically, gated
 * `integrations:manage` + SELLER capability) -- a deliberate, documented
 * divergence in this port: the same underlying tables now have two
 * independently-gated write surfaces, matching how source itself treats
 * CreateClient (Developer Portal, arbitrary scopes) and this port's
 * POS-credential issuance (hard-coded `invoices:submit` scope) as
 * genuinely different commands.
 *
 * Honest, discoverable consequence of that same divergence:
 * DeveloperConformanceEvaluator's SCOPES_DECLARED check enforces source's
 * own dot-separated `resource.action` scope pattern, but
 * PosApiClientService::SCOPE_INVOICE_SUBMIT ('invoices:submit') uses this
 * port's own colon-separated permission-code convention instead (load-
 * bearing: App\Http\Middleware\AuthenticatePosApiClient checks that exact
 * string, so it cannot be reformatted to satisfy the pattern without
 * breaking real POS authentication). A POS-issued credential therefore
 * always fails SCOPES_DECLARED under RunConformance -- ported faithfully
 * rather than loosened, since weakening the pattern would silently accept
 * scope strings source's own validateClientCreation would reject.
 */
class DeveloperPlatformService
{
    /**
     * CreateClient. `api_clients.organisation_id` is NOT NULL and this
     * command resolves the organisation from the actor's own taxpayer
     * directly (not via App\Support\Business\OrganisationResolver's
     * national-scope "pick any active organisation" branch, and no
     * `?organisation_id=` override) -- a national/platform-scope actor has
     * no taxpayer/organisation of their own to create a client under, per
     * source's own resolveOrganisation. A DeveloperAccount is get-or-created
     * (one per organisation+actor pair) the first time that actor creates a
     * client -- no separate "create account" verb is named. The credential
     * itself stays honest: a client_key (a real, generatable, non-secret
     * identifier) is issued immediately, but status stays
     * PENDING_CREDENTIAL_PROVISIONING and credential_reference is always a
     * pointer string (secret-manager://pending/<id>), never a secret value
     * -- there is no real secret manager integrated in this environment to
     * mint a live production credential.
     */
    public function createClient(User $actor, array $payload, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = DeveloperCommandValidator::clientCreation($payload);
        $organisation = $this->resolveOrganisationForCreation($actor);

        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'CREATE_CLIENT', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentClient(ApiClient::findOrFail($prior));
        }

        $now = now();
        $developerAccountId = $this->getOrCreateDeveloperAccount($organisation, $actor, $now);

        $clientId = (string) Str::uuid();
        $clientKey = self::slugify($input['name']).'_'.substr($clientId, 0, 8);
        $credentialReference = "secret-manager://pending/{$clientId}";

        DB::transaction(function () use ($organisation, $developerAccountId, $input, $actor, $now, $clientId, $clientKey, $credentialReference, $idempotencyKey, $requestHash, $correlationId) {
            ApiClient::create([
                'id' => $clientId, 'organisation_id' => $organisation->id, 'developer_account_id' => $developerAccountId,
                'name' => $input['name'], 'client_key' => $clientKey, 'scopes' => json_encode($input['scopes']),
                'credential_reference' => $credentialReference, 'status' => 'PENDING_CREDENTIAL_PROVISIONING',
                'rate_limit_profile' => $input['rate_limit_profile'], 'last_rotated_at' => null, 'expires_at' => null,
                'created_by' => $actor->id, 'created_at' => $now,
            ]);
            CredentialRef::create([
                'id' => (string) Str::uuid(), 'api_client_id' => $clientId, 'credential_reference' => $credentialReference,
                'status' => 'ACTIVE', 'issued_by' => $actor->id, 'issued_at' => $now,
            ]);

            CommandLedger::record($actor->id, 'CREATE_CLIENT', $idempotencyKey, $requestHash, 'API_CLIENT', $clientId, $now);
            CommandLedger::outbox('API_CLIENT', $clientId, 'ApiClientCreated', $organisation->id, [
                'api_client_id' => $clientId, 'developer_account_id' => $developerAccountId,
                'scopes' => $input['scopes'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'API_CLIENT_CREATED', 'API_CLIENT', $clientId, [
                'developerAccountId' => $developerAccountId, 'scopes' => $input['scopes'],
                'rateLimitProfile' => $input['rate_limit_profile'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->presentClient(ApiClient::findOrFail($clientId));
    }

    public function rotateCredential(Organisation $organisation, string $apiClientId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $client = $this->loadClient($organisation, $apiClientId);
        if ($client->status === 'REVOKED') {
            throw new RepositoryConflictException("A revoked API client's credential cannot be rotated.");
        }

        $requestHash = CommandLedger::requestHash(['api_client_id' => $apiClientId, 'action' => 'ROTATE']);
        $prior = CommandLedger::prior($actor->id, 'ROTATE_CREDENTIAL', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentClient(ApiClient::findOrFail($prior));
        }

        $now = now();
        $newReference = "secret-manager://pending/{$apiClientId}/".Str::random(8);

        DB::transaction(function () use ($client, $actor, $now, $newReference, $idempotencyKey, $requestHash, $correlationId) {
            CredentialRef::where('api_client_id', $client->id)->where('status', 'ACTIVE')->update(['status' => 'ROTATED']);
            CredentialRef::create([
                'id' => (string) Str::uuid(), 'api_client_id' => $client->id, 'credential_reference' => $newReference,
                'status' => 'ACTIVE', 'issued_by' => $actor->id, 'issued_at' => $now,
            ]);
            $client->update(['credential_reference' => $newReference, 'last_rotated_at' => $now]);

            CommandLedger::record($actor->id, 'ROTATE_CREDENTIAL', $idempotencyKey, $requestHash, 'API_CLIENT', $client->id, $now);
            CommandLedger::outbox('API_CLIENT', $client->id, 'ApiClientCredentialRotated', $client->organisation_id, [
                'api_client_id' => $client->id, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'API_CLIENT_CREDENTIAL_ROTATED', 'API_CLIENT', $client->id, ['correlationId' => $correlationId], $now);
        });

        return $this->presentClient($client->refresh());
    }

    /**
     * RevokeCredential: terminal -- marks the current ACTIVE credential_refs
     * row REVOKED and the client itself REVOKED. No un-revoke verb is named
     * by source, so none exists here either.
     */
    public function revokeCredential(Organisation $organisation, string $apiClientId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = DeveloperCommandValidator::credentialRevocation($payload);
        $client = $this->loadClient($organisation, $apiClientId);
        if ($client->status === 'REVOKED') {
            throw new RepositoryConflictException("This API client's credential has already been revoked.");
        }

        $requestHash = CommandLedger::requestHash(['api_client_id' => $apiClientId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'REVOKE_CREDENTIAL', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentClient(ApiClient::findOrFail($prior));
        }

        $now = now();
        DB::transaction(function () use ($client, $actor, $now, $input, $idempotencyKey, $requestHash, $correlationId) {
            CredentialRef::where('api_client_id', $client->id)->where('status', 'ACTIVE')->update([
                'status' => 'REVOKED', 'revoked_by' => $actor->id, 'revoked_at' => $now, 'revocation_reason' => $input['reason'],
            ]);
            $client->update(['status' => 'REVOKED']);

            CommandLedger::record($actor->id, 'REVOKE_CREDENTIAL', $idempotencyKey, $requestHash, 'API_CLIENT', $client->id, $now);
            CommandLedger::outbox('API_CLIENT', $client->id, 'ApiClientCredentialRevoked', $client->organisation_id, [
                'api_client_id' => $client->id, 'reason' => $input['reason'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'API_CLIENT_CREDENTIAL_REVOKED', 'API_CLIENT', $client->id, [
                'reason' => $input['reason'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->presentClient($client->refresh());
    }

    public function runConformance(Organisation $organisation, string $apiClientId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $client = $this->loadClient($organisation, $apiClientId);

        $requestHash = CommandLedger::requestHash(['api_client_id' => $apiClientId, 'action' => 'RUN_CONFORMANCE']);
        $prior = CommandLedger::prior($actor->id, 'RUN_CONFORMANCE', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentTestRun(TestRun::findOrFail($prior));
        }

        $currentCredential = CredentialRef::where('api_client_id', $client->id)->orderByDesc('issued_at')->first();
        $checks = DeveloperConformanceEvaluator::evaluate([
            'scopes' => $client->scopeList(),
            'rate_limit_profile' => $client->rate_limit_profile,
            'client_status' => $client->status,
            'current_credential_status' => $currentCredential?->status,
        ]);
        $outcome = DeveloperConformanceEvaluator::outcome($checks);

        $runId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($client, $actor, $now, $runId, $checks, $outcome, $idempotencyKey, $requestHash, $correlationId) {
            TestRun::create([
                'id' => $runId, 'api_client_id' => $client->id, 'checks' => json_encode($checks),
                'outcome' => $outcome, 'run_by' => $actor->id, 'run_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'RUN_CONFORMANCE', $idempotencyKey, $requestHash, 'TEST_RUN', $runId, $now);
            CommandLedger::outbox('API_CLIENT', $client->id, $outcome === 'PASSED' ? 'ApiClientConformancePassed' : 'ApiClientConformanceFailed', $client->organisation_id, [
                'api_client_id' => $client->id, 'test_run_id' => $runId, 'outcome' => $outcome,
                'test_suite_version' => DeveloperConformanceEvaluator::TEST_SUITE_VERSION, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'API_CLIENT_CONFORMANCE_RUN', 'API_CLIENT', $client->id, [
                'testRunId' => $runId, 'outcome' => $outcome, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->presentTestRun(TestRun::findOrFail($runId));
    }

    /** Ported from lib/data/developer-repository.ts's resolveOrganisation -- deliberately NOT App\Support\Business\OrganisationResolver: that helper lets a national-scope actor pick any active organisation, but source rejects such an actor outright here (no taxpayer of their own to create a client under), and accepts no `organisation_id` override. */
    private function resolveOrganisationForCreation(User $actor): Organisation
    {
        if ($actor->taxpayer_id === null) {
            throw new AuthorizationException('An API client must belong to an organisation; national/platform-scope actors have none to create one under.');
        }
        $organisation = Organisation::where('taxpayer_id', $actor->taxpayer_id)->where('status', 'ACTIVE')->first();
        if (! $organisation) {
            throw new AuthorizationException('Your account is not assigned to an active organisation.');
        }

        return $organisation;
    }

    private function getOrCreateDeveloperAccount(Organisation $organisation, User $actor, \DateTimeInterface $now): string
    {
        $existing = DeveloperAccount::where('organisation_id', $organisation->id)->where('owner_user_id', $actor->id)->first();
        if ($existing) {
            return $existing->id;
        }
        $account = DeveloperAccount::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'owner_user_id' => $actor->id,
            'display_name' => "{$actor->name}'s developer account", 'status' => 'ACTIVE', 'created_at' => $now,
        ]);

        return $account->id;
    }

    private static function slugify(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        $slug = mb_substr($slug, 0, 40);

        return $slug !== '' ? $slug : 'client';
    }

    private function loadClient(Organisation $organisation, string $apiClientId): ApiClient
    {
        $client = ApiClient::where('id', $apiClientId)->where('organisation_id', $organisation->id)->first();
        if (! $client) {
            throw new BusinessResourceException('API client was not found in your organisation.', 404);
        }

        return $client;
    }

    /** @return array<string, mixed> */
    private function presentClient(ApiClient $client): array
    {
        return [
            'id' => $client->id, 'name' => $client->name, 'client_key' => $client->client_key,
            'scopes' => $client->scopeList(), 'status' => $client->status, 'rate_limit_profile' => $client->rate_limit_profile,
            'last_rotated_at' => $client->last_rotated_at?->toIso8601String(), 'expires_at' => $client->expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentTestRun(TestRun $run): array
    {
        return [
            'id' => $run->id, 'api_client_id' => $run->api_client_id, 'checks' => $run->checkList(),
            'outcome' => $run->outcome, 'run_by' => $run->run_by, 'run_at' => $run->run_at?->toIso8601String(),
        ];
    }
}
