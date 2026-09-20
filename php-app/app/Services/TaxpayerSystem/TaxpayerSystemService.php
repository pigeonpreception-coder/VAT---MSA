<?php

namespace App\Services\TaxpayerSystem;

use App\Domain\TaxpayerSystem\TaxpayerSystemValidator;
use App\Exceptions\RepositoryConflictException;
use App\Exceptions\TaxpayerSystemResourceException;
use App\Models\Organisation;
use App\Models\TaxpayerSystemRegistration;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/taxpayer-system-repository.ts -- the NamRA e-VAT MS
 * Registered Taxpayer Systems Framework (master prompt section 6):
 * RegisterTaxpayerSystem/ApproveTaxpayerSystem/SuspendTaxpayerSystem/
 * RecordTaxpayerSystemSync/GetTaxpayerSystem/ListTaxpayerSystems. Never
 * ported to this migration -- the `taxpayer_system_registrations` table
 * did not exist in this port at all until now, confirmed by a full-repo
 * grep finding no migration, model, or route referencing it.
 */
class TaxpayerSystemService
{
    /** @return array{organisation_id: string, taxpayer_id: string, vat_number: string, tin: ?string} */
    private function resolveOrganisationForRegistration(User $actor): array
    {
        if (! $actor->taxpayer_id) {
            throw new AuthorizationException("Only a taxpayer's own organisation may register a system.");
        }
        $organisation = Organisation::with('taxpayer')->where('taxpayer_id', $actor->taxpayer_id)->where('status', 'ACTIVE')->first();
        if (! $organisation) {
            throw new AuthorizationException('Your account is not assigned to an active organisation.');
        }

        return [
            'organisation_id' => $organisation->id, 'taxpayer_id' => $organisation->taxpayer_id,
            'vat_number' => $organisation->taxpayer->vat_number, 'tin' => $organisation->taxpayer->tin,
        ];
    }

    /**
     * A tenant actor may only ever load their own organisation's
     * registration (register/suspend/sync are all self-service). A
     * national-scope actor may load any organisation's registration --
     * approving/reviewing another taxpayer's system is the entire point
     * of the approval gate.
     */
    private function loadRegistrationForActor(User $actor, string $id): TaxpayerSystemRegistration
    {
        $registration = TaxpayerSystemRegistration::find($id);
        if (! $registration) {
            throw new TaxpayerSystemResourceException('Taxpayer system registration was not found.', 404);
        }
        if (! TenantScope::isNational($actor)) {
            if (! $actor->taxpayer_id) {
                throw new AuthorizationException("Only that organisation's own taxpayer may manage its registered systems.");
            }
            $owned = Organisation::where('taxpayer_id', $actor->taxpayer_id)->where('id', $registration->organisation_id)->exists();
            if (! $owned) {
                throw new AuthorizationException('This registration is outside your authorised organisation scope.');
            }
        }

        return $registration;
    }

    /** RegisterTaxpayerSystem. The submitted vat_registration_number (and tin, if provided) must match the actor's own taxpayer record -- this framework registers a taxpayer's own system, never a claim about someone else's identity. */
    public function register(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = TaxpayerSystemValidator::registration($payload);
        $organisation = $this->resolveOrganisationForRegistration($actor);

        if ($input['vat_registration_number'] !== mb_strtoupper($organisation['vat_number'])) {
            throw new TaxpayerSystemResourceException("vat_registration_number must match your own taxpayer's registered VAT number.");
        }
        if ($input['tin'] !== null && $input['tin'] !== mb_strtoupper((string) $organisation['tin'])) {
            throw new TaxpayerSystemResourceException('tin must match your own taxpayer\'s registered TIN.');
        }

        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'REGISTER_TAXPAYER_SYSTEM', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(TaxpayerSystemRegistration::find($prior));
        }

        $existing = TaxpayerSystemRegistration::where('organisation_id', $organisation['organisation_id'])
            ->where('system_name', $input['system_name'])->where('system_vendor', $input['system_vendor'])->first();
        if ($existing) {
            throw new RepositoryConflictException("A registration for {$input['system_name']} ({$input['system_vendor']}) already exists as {$existing->id}.");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($id, $organisation, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            TaxpayerSystemRegistration::create([
                'id' => $id, 'organisation_id' => $organisation['organisation_id'], 'taxpayer_id' => $organisation['taxpayer_id'],
                'vat_registration_number' => $input['vat_registration_number'], 'tin' => $input['tin'],
                'company_registration_number' => $input['company_registration_number'], 'system_name' => $input['system_name'],
                'system_vendor' => $input['system_vendor'], 'system_category' => $input['system_category'],
                'credential_reference' => $input['credential_reference'], 'api_status' => 'NOT_CONNECTED',
                'registration_status' => 'DRAFT', 'security_status' => 'NOT_ASSESSED', 'last_synchronization_at' => null,
                'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'REGISTER_TAXPAYER_SYSTEM', $idempotencyKey, $requestHash, 'TAXPAYER_SYSTEM_REGISTRATION', $id, $now);
            CommandLedger::outbox('TAXPAYER_SYSTEM_REGISTRATION', $id, 'TaxpayerSystemRegistered', $organisation['organisation_id'], [
                'system_name' => $input['system_name'], 'system_vendor' => $input['system_vendor'],
                'organisation_id' => $organisation['organisation_id'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'TAXPAYER_SYSTEM_REGISTERED', 'TAXPAYER_SYSTEM_REGISTRATION', $id, [
                'systemName' => $input['system_name'], 'systemVendor' => $input['system_vendor'],
                'organisationId' => $organisation['organisation_id'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->present(TaxpayerSystemRegistration::find($id));
    }

    private function transition(string $id, string $action, User $actor, string $idempotencyKey, string $correlationId, array $extraDetails): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $registration = $this->loadRegistrationForActor($actor, $id);
        $target = TaxpayerSystemValidator::assertTransition($action, $registration->registration_status);

        $requestHash = CommandLedger::requestHash(['registration_id' => $id, 'action' => $action, 'extra_details' => $extraDetails]);
        $command = $action === 'APPROVE' ? 'APPROVE_TAXPAYER_SYSTEM' : 'SUSPEND_TAXPAYER_SYSTEM';
        $prior = CommandLedger::prior($actor->id, $command, $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(TaxpayerSystemRegistration::find($prior));
        }

        $now = now();
        $fromStatus = $registration->registration_status;
        DB::transaction(function () use ($id, $target, $action, $registration, $fromStatus, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $extraDetails, $command) {
            TaxpayerSystemRegistration::where('id', $id)->update(['registration_status' => $target, 'updated_at' => $now]);
            CommandLedger::record($actor->id, $command, $idempotencyKey, $requestHash, 'TAXPAYER_SYSTEM_REGISTRATION', $id, $now);
            CommandLedger::outbox('TAXPAYER_SYSTEM_REGISTRATION', $id, $action === 'APPROVE' ? 'TaxpayerSystemApproved' : 'TaxpayerSystemSuspended', $registration->organisation_id, array_merge([
                'taxpayer_system_registration_id' => $id, 'from_status' => $fromStatus, 'to_status' => $target, 'correlation_id' => $correlationId,
            ], $extraDetails), $now);
            AuditService::append($actor, $action === 'APPROVE' ? 'TAXPAYER_SYSTEM_APPROVED' : 'TAXPAYER_SYSTEM_SUSPENDED', 'TAXPAYER_SYSTEM_REGISTRATION', $id, array_merge([
                'fromStatus' => $fromStatus, 'toStatus' => $target, 'correlationId' => $correlationId,
            ], $extraDetails), $now);
        });

        return $this->present(TaxpayerSystemRegistration::find($id));
    }

    /**
     * ApproveTaxpayerSystem: DRAFT or SUSPENDED -> APPROVED. Restricted to
     * a national-scope (NamRA) actor regardless of what the caller's
     * permission alone would otherwise allow -- the same isNationalScope
     * defense-in-depth pattern this migration's own compliance/risk
     * actions already use.
     */
    public function approve(string $id, User $actor, string $idempotencyKey, string $correlationId): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may approve a taxpayer system registration.');
        }

        return $this->transition($id, 'APPROVE', $actor, $idempotencyKey, $correlationId, []);
    }

    /** SuspendTaxpayerSystem: APPROVED -> SUSPENDED. Self-service -- the owning taxpayer may pause their own system (e.g. decommissioning). Requires a recorded reason. */
    public function suspend(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        $input = TaxpayerSystemValidator::suspension($payload);

        return $this->transition($id, 'SUSPEND', $actor, $idempotencyKey, $correlationId, ['reason' => $input['reason']]);
    }

    /** RecordSynchronization: the taxpayer's own system reports its post-sync connectivity state and a fresh last_synchronization_at timestamp. Only meaningful for an APPROVED registration. */
    public function recordSync(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = TaxpayerSystemValidator::sync($payload);
        $registration = $this->loadRegistrationForActor($actor, $id);
        if ($registration->registration_status !== 'APPROVED') {
            throw new RepositoryConflictException('Synchronization can only be recorded for an approved registration.');
        }

        $requestHash = CommandLedger::requestHash(['registration_id' => $id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'RECORD_TAXPAYER_SYSTEM_SYNC', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(TaxpayerSystemRegistration::find($prior));
        }

        $now = now();
        DB::transaction(function () use ($id, $input, $registration, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            TaxpayerSystemRegistration::where('id', $id)->update(['api_status' => $input['api_status'], 'last_synchronization_at' => $now, 'updated_at' => $now]);
            CommandLedger::record($actor->id, 'RECORD_TAXPAYER_SYSTEM_SYNC', $idempotencyKey, $requestHash, 'TAXPAYER_SYSTEM_REGISTRATION', $id, $now);
            CommandLedger::outbox('TAXPAYER_SYSTEM_REGISTRATION', $id, 'TaxpayerSystemSynchronized', $registration->organisation_id, [
                'taxpayer_system_registration_id' => $id, 'api_status' => $input['api_status'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'TAXPAYER_SYSTEM_SYNCHRONIZED', 'TAXPAYER_SYSTEM_REGISTRATION', $id, [
                'apiStatus' => $input['api_status'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->present(TaxpayerSystemRegistration::find($id));
    }

    /** GetTaxpayerSystem: a single registration, subject to the same tenant/national load-scope rule as the write commands. */
    public function show(string $id, User $actor): array
    {
        return $this->present($this->loadRegistrationForActor($actor, $id));
    }

    /**
     * ListTaxpayerSystems: a tenant actor sees only their own
     * organisation's registrations; a national-scope actor sees every
     * registered system across all taxpayers (the whole point of NamRA
     * needing this register at all).
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(User $actor): array
    {
        if (TenantScope::isNational($actor)) {
            return TaxpayerSystemRegistration::orderByDesc('created_at')->get()->map(fn (TaxpayerSystemRegistration $r) => $this->present($r))->all();
        }
        if (! $actor->taxpayer_id) {
            throw new AuthorizationException('Your account is not assigned to an active organisation.');
        }

        return TaxpayerSystemRegistration::whereHas('organisation', fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
            ->orderByDesc('created_at')->get()->map(fn (TaxpayerSystemRegistration $r) => $this->present($r))->all();
    }

    /** @return array<string, mixed> */
    private function present(?TaxpayerSystemRegistration $registration): array
    {
        if (! $registration) {
            throw new RepositoryConflictException('The idempotent taxpayer system resource is no longer available.');
        }

        return [
            'id' => $registration->id, 'organisation_id' => $registration->organisation_id, 'taxpayer_id' => $registration->taxpayer_id,
            'vat_registration_number' => $registration->vat_registration_number, 'tin' => $registration->tin,
            'company_registration_number' => $registration->company_registration_number, 'system_name' => $registration->system_name,
            'system_vendor' => $registration->system_vendor, 'system_category' => $registration->system_category,
            'credential_reference' => $registration->credential_reference, 'api_status' => $registration->api_status,
            'registration_status' => $registration->registration_status, 'security_status' => $registration->security_status,
            'last_synchronization_at' => optional($registration->last_synchronization_at)->toISOString(),
            'created_by' => $registration->created_by, 'created_at' => optional($registration->created_at)->toISOString(),
            'updated_at' => optional($registration->updated_at)->toISOString(),
        ];
    }
}
