<?php

namespace App\Services\Saas;

use App\Domain\Saas\SaasValidator;
use App\Exceptions\RepositoryConflictException;
use App\Exceptions\SaasResourceException;
use App\Models\SaasApplication;
use App\Models\SaasConformanceRun;
use App\Models\SaasEnvironmentApproval;
use App\Models\SaasProvider;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/saas-repository.ts -- Module 10 Phase C: SaaS
 * provider onboarding (RegisterProvider/SubmitConformance/GetUsage). The
 * `saas_providers`/`saas_applications`/`saas_conformance_runs`/
 * `saas_environment_approvals` tables were already schema-ported (each
 * migration's own comment says so verbatim: "No command references this
 * table yet in this migration") -- this is the command/validation/service
 * layer that was still missing, the same "schema exists, no command uses
 * it yet" gap `payment_instructions` had before the Payment connector.
 */
class SaasService
{
    /**
     * A tenant actor may only ever act on a SaaS application/provider they
     * themselves registered -- registration here is per-actor (a developer
     * partner's own account), not per-organisation like the Taxpayer
     * Systems framework. A national-scope actor may act on any.
     */
    private function loadApplicationForActor(User $actor, string $applicationId): SaasApplication
    {
        $application = SaasApplication::with('provider')->find($applicationId);
        if (! $application) {
            throw new SaasResourceException('SaaS application was not found.', 404);
        }
        if ($application->provider->registered_by !== $actor->id && ! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only the registering actor or a national-scope actor may act on this SaaS application.');
        }

        return $application;
    }

    private function loadProviderForActor(User $actor, string $providerId): SaasProvider
    {
        $provider = SaasProvider::find($providerId);
        if (! $provider) {
            throw new SaasResourceException('SaaS provider was not found.', 404);
        }
        if ($provider->registered_by !== $actor->id && ! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only the registering actor or a national-scope actor may view this SaaS provider\'s usage.');
        }

        return $provider;
    }

    /**
     * RegisterProvider. No separate "create application" verb exists in
     * the playbook's own list (RegisterProvider, SubmitConformance,
     * GetUsage) -- a provider registers with exactly one named application
     * in this same call, creating both the SaaSProvider and Application
     * rows atomically. provider_key reuses the same vocabulary Module 10
     * Phase A's integration_connections.provider_key already uses, though
     * nothing in this phase gates one against the other -- that governance
     * link is explicitly out of scope (see the source's own "Required
     * closure" note in the migration matrix).
     */
    public function registerProvider(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = SaasValidator::providerRegistration($payload);

        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'REGISTER_PROVIDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentWithApplication(SaasProvider::find($prior));
        }

        $existing = SaasProvider::where('provider_key', $input['provider_key'])->first();
        if ($existing) {
            throw new RepositoryConflictException("A SaaS provider for {$input['provider_key']} already exists as {$existing->id}.");
        }

        $providerId = (string) Str::uuid();
        $applicationId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($providerId, $applicationId, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            SaasProvider::create([
                'id' => $providerId, 'provider_key' => $input['provider_key'], 'legal_name' => $input['legal_name'],
                'contact_email' => $input['contact_email'], 'category' => $input['category'], 'status' => 'ACTIVE',
                'registered_by' => $actor->id, 'registered_at' => $now,
            ]);
            SaasApplication::create([
                'id' => $applicationId, 'saas_provider_id' => $providerId, 'name' => $input['application']['name'],
                'description' => $input['application']['description'],
                'requested_capabilities' => AuditService::canonicalJson($input['application']['requested_capabilities']),
                'endpoint_reference' => $input['application']['endpoint_reference'], 'status' => 'REGISTERED',
                'created_by' => $actor->id, 'created_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'REGISTER_PROVIDER', $idempotencyKey, $requestHash, 'SAAS_PROVIDER', $providerId, $now);
            CommandLedger::outbox('SAAS_PROVIDER', $providerId, 'SaasProviderRegistered', $providerId, [
                'provider_key' => $input['provider_key'], 'application_id' => $applicationId, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'SAAS_PROVIDER_REGISTERED', 'SAAS_PROVIDER', $providerId, [
                'providerKey' => $input['provider_key'], 'applicationId' => $applicationId, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->presentWithApplication(SaasProvider::find($providerId));
    }

    /**
     * SubmitConformance. Runs the fixed, code-versioned conformance
     * harness (SaasValidator::evaluateConformance) and records both the
     * run and its consequence for that environment's EnvironmentApproval
     * -- GRANTED for a PASSED SANDBOX run, DENIED for a FAILED run of
     * either environment, and, for a PASSED PRODUCTION run,
     * AWAITING_AUTHORITY rather than GRANTED: production onboarding is a
     * governance decision this phase deliberately does not build a path to
     * grant automatically from a self-submitted, self-run conformance
     * suite alone -- the same "fail closed on an unconfirmed authority"
     * posture ITAS/Payment/HSM already apply elsewhere in this codebase.
     */
    public function submitConformance(string $applicationId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = SaasValidator::conformanceSubmission($payload);
        $application = $this->loadApplicationForActor($actor, $applicationId);

        $requestHash = CommandLedger::requestHash(['application_id' => $applicationId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'SUBMIT_CONFORMANCE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentRun(SaasConformanceRun::find($prior));
        }

        $priorSandboxPassed = $input['environment'] === 'PRODUCTION'
            && SaasConformanceRun::where('saas_application_id', $applicationId)->where('environment', 'SANDBOX')->where('outcome', 'PASSED')->exists();

        $requestedCapabilities = json_decode($application->requested_capabilities, true) ?? [];
        $checks = SaasValidator::evaluateConformance(['requested_capabilities' => $requestedCapabilities], $input, $priorSandboxPassed);
        $outcome = SaasValidator::conformanceOutcome($checks);
        $approvalStatus = $outcome === 'FAILED' ? 'DENIED' : ($input['environment'] === 'SANDBOX' ? 'GRANTED' : 'AWAITING_AUTHORITY');

        $runId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($runId, $applicationId, $input, $checks, $outcome, $approvalStatus, $application, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            SaasConformanceRun::create([
                'id' => $runId, 'saas_application_id' => $applicationId, 'environment' => $input['environment'],
                'test_suite_version' => SaasValidator::CONFORMANCE_SUITE_VERSION, 'checks' => AuditService::canonicalJson($checks),
                'outcome' => $outcome, 'submitted_by' => $actor->id, 'submitted_at' => $now,
            ]);
            $existingApproval = SaasEnvironmentApproval::where('saas_application_id', $applicationId)->where('environment', $input['environment'])->first();
            if ($existingApproval) {
                $existingApproval->update(['status' => $approvalStatus, 'conformance_run_id' => $runId, 'updated_at' => $now]);
            } else {
                SaasEnvironmentApproval::create([
                    'id' => (string) Str::uuid(), 'saas_application_id' => $applicationId, 'environment' => $input['environment'],
                    'status' => $approvalStatus, 'conformance_run_id' => $runId, 'updated_at' => $now,
                ]);
            }
            CommandLedger::record($actor->id, 'SUBMIT_CONFORMANCE', $idempotencyKey, $requestHash, 'SAAS_CONFORMANCE_RUN', $runId, $now);
            CommandLedger::outbox('SAAS_APPLICATION', $applicationId, $outcome === 'PASSED' ? 'SaasConformancePassed' : 'SaasConformanceFailed', $application->saas_provider_id, [
                'application_id' => $applicationId, 'environment' => $input['environment'], 'outcome' => $outcome,
                'approval_status' => $approvalStatus, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'SAAS_CONFORMANCE_SUBMITTED', 'SAAS_APPLICATION', $applicationId, [
                'environment' => $input['environment'], 'outcome' => $outcome, 'approvalStatus' => $approvalStatus, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->presentRun(SaasConformanceRun::find($runId));
    }

    /**
     * GetUsage. The only read this phase names, so it doubles as the full
     * provider-detail view: the provider row, every registered application
     * with its conformance/environment-approval standing, and -- tying
     * back into Module 10 Phase A's own generic connector model -- real
     * usage: every integration_connections row sharing this provider's
     * provider_key, and their aggregate sync_jobs history. A provider
     * vetted here and a tenant's own RegisterIntegration call are today two
     * independent, unlinked actions (see registerProvider's own comment);
     * this read is what makes that real-world usage visible despite that.
     */
    public function getUsage(string $providerId, User $actor): array
    {
        $provider = $this->loadProviderForActor($actor, $providerId);
        $applications = SaasApplication::where('saas_provider_id', $providerId)->orderBy('created_at')->get();
        $approvals = SaasEnvironmentApproval::whereIn('saas_application_id', $applications->pluck('id'))->get();
        $connections = DB::table('integration_connections')
            ->select('id', 'organisation_id', 'configuration_status', 'operational_status')
            ->where('provider_key', $provider->provider_key)->get();
        $connectionIds = $connections->pluck('id')->all();
        $syncStats = count($connectionIds) === 0
            ? ['totalJobs' => 0, 'failedJobs' => 0]
            : (array) DB::table('sync_jobs')
                ->selectRaw('COUNT(*) AS totalJobs, SUM(CASE WHEN status=\'FAILED\' THEN 1 ELSE 0 END) AS failedJobs')
                ->whereIn('integration_connection_id', $connectionIds)->first();

        return [
            'provider' => $this->present($provider),
            'applications' => $applications->map(fn (SaasApplication $a) => $this->presentApplication($a))->all(),
            'environmentApprovals' => $approvals->map(fn (SaasEnvironmentApproval $a) => $this->presentApproval($a))->all(),
            'connections' => $connections->map(fn ($c) => (array) $c)->all(),
            'connectionCount' => $connections->count(),
            'syncStats' => ['totalJobs' => (int) ($syncStats['totalJobs'] ?? 0), 'failedJobs' => (int) ($syncStats['failedJobs'] ?? 0)],
        ];
    }

    /**
     * ListProviders: not a command the source playbook names (only
     * RegisterProvider/SubmitConformance/GetUsage), added purely to back
     * this migration's own Blade register/browse page -- a tenant actor
     * sees only the SaaS providers they themselves registered; a
     * national-scope actor sees every provider onboarded across the
     * platform.
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(User $actor): array
    {
        $query = SaasProvider::query()->orderByDesc('registered_at');
        if (! TenantScope::isNational($actor)) {
            $query->where('registered_by', $actor->id);
        }

        return $query->get()->map(fn (SaasProvider $p) => $this->present($p))->all();
    }

    /** @return array<string, mixed> */
    private function present(?SaasProvider $provider): array
    {
        if (! $provider) {
            throw new RepositoryConflictException('The idempotent SaaS resource is no longer available.');
        }

        return [
            'id' => $provider->id, 'provider_key' => $provider->provider_key, 'legal_name' => $provider->legal_name,
            'contact_email' => $provider->contact_email, 'category' => $provider->category, 'status' => $provider->status,
            'registered_by' => $provider->registered_by, 'registered_at' => optional($provider->registered_at)->toISOString(),
        ];
    }

    /** RegisterProvider's own response shape -- source returns `{ ...provider, application }`, the one place the provider's application rides alongside it rather than needing a separate GetUsage read. */
    private function presentWithApplication(?SaasProvider $provider): array
    {
        $presented = $this->present($provider);
        $application = SaasApplication::where('saas_provider_id', $provider->id)->first();
        $presented['application'] = $application ? $this->presentApplication($application) : null;

        return $presented;
    }

    /** @return array<string, mixed> */
    private function presentApplication(SaasApplication $application): array
    {
        return [
            'id' => $application->id, 'saas_provider_id' => $application->saas_provider_id, 'name' => $application->name,
            'description' => $application->description, 'requested_capabilities' => json_decode($application->requested_capabilities, true) ?? [],
            'endpoint_reference' => $application->endpoint_reference, 'status' => $application->status,
            'created_by' => $application->created_by, 'created_at' => optional($application->created_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentApproval(SaasEnvironmentApproval $approval): array
    {
        return [
            'id' => $approval->id, 'saas_application_id' => $approval->saas_application_id, 'environment' => $approval->environment,
            'status' => $approval->status, 'conformance_run_id' => $approval->conformance_run_id,
            'updated_at' => optional($approval->updated_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRun(?SaasConformanceRun $run): array
    {
        if (! $run) {
            throw new RepositoryConflictException('The idempotent SaaS resource is no longer available.');
        }

        return [
            'id' => $run->id, 'saas_application_id' => $run->saas_application_id, 'environment' => $run->environment,
            'test_suite_version' => $run->test_suite_version, 'checks' => json_decode($run->checks, true) ?? [],
            'outcome' => $run->outcome, 'submitted_by' => $run->submitted_by,
            'submitted_at' => optional($run->submitted_at)->toISOString(),
        ];
    }
}
