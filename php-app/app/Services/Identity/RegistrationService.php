<?php

namespace App\Services\Identity;

use App\Exceptions\RepositoryConflictException;
use App\Integrations\Itas\ItasIdentityPort;
use App\Integrations\Itas\ItasIntegrationUnavailableException;
use App\Models\Branch;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationMembership;
use App\Models\OutboxEvent;
use App\Models\RegistrationApplication;
use App\Models\RegistrationVerification;
use App\Models\Taxpayer;
use App\Models\TaxpayerIdentifier;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ported from lib/data/identity-repository.ts's submitRegistrationApplication
 * and decideRegistrationApplication. A taxpayer/organisation does not exist
 * until an APPROVE decision materializes it -- see decide() below.
 */
class RegistrationService
{
    public function __construct(private readonly ItasIdentityPort $itas) {}

    public function submit(array $registration, User $actor, string $idempotencyKey, string $correlationId): RegistrationApplication
    {
        if (mb_strlen($idempotencyKey) < 16 || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key must contain 16 to 128 characters.']);
        }

        $requestHash = hash('sha256', AuditService::canonicalJson($registration));

        $prior = RegistrationApplication::where('submitted_by', $actor->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            if ($prior->request_hash !== $requestHash) {
                throw new RepositoryConflictException('The idempotency key was already used for a different registration application.');
            }
            return $prior;
        }

        $registered = Taxpayer::where('vat_number', $registration['vat_number'])->orWhere('tin', $registration['tin'])->exists()
            || TaxpayerIdentifier::where(function ($q) use ($registration) {
                $q->where('identifier_type', 'VAT_NUMBER')->where('identifier_value', $registration['vat_number']);
            })->orWhere(function ($q) use ($registration) {
                $q->where('identifier_type', 'TIN')->where('identifier_value', $registration['tin']);
            })->exists();
        if ($registered) {
            throw new RepositoryConflictException('A canonical taxpayer already exists for the supplied VAT number or TIN.');
        }

        $duplicate = RegistrationApplication::where(function ($q) use ($registration) {
            $q->where('vat_number', $registration['vat_number'])->orWhere('tin', $registration['tin']);
        })->whereIn('status', ['PENDING_VERIFICATION', 'UNDER_REVIEW', 'VERIFIED'])->first();
        if ($duplicate) {
            throw new RepositoryConflictException("An active registration application already exists as {$duplicate->id}.");
        }

        $now = now();
        $verificationStatus = 'AWAITING_PROVIDER_CONTRACT';
        $verificationReference = 'itas-verify:'.Str::uuid();
        $responseHash = null;
        $checkedAt = $now;
        $expiresAt = null;

        try {
            $result = $this->itas->verifyTaxpayer([
                'vat_number' => $registration['vat_number'],
                'tin' => $registration['tin'],
                'company_registration_number' => $registration['company_registration_number'] ?? null,
                'correlation_id' => $correlationId,
            ]);
            $verificationStatus = 'VERIFIED';
            $verificationReference = $result['request_reference'];
            $responseHash = $result['response_hash'];
            $checkedAt = $result['checked_at'];
            $expiresAt = $result['expires_at'] ?? null;
        } catch (ItasIntegrationUnavailableException) {
            // Fail-closed, matching the source exactly: stays AWAITING_PROVIDER_CONTRACT.
        }

        return DB::transaction(function () use ($registration, $actor, $idempotencyKey, $requestHash, $now, $verificationStatus, $verificationReference, $responseHash, $checkedAt, $expiresAt, $correlationId) {
            $application = RegistrationApplication::create([
                'id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'vat_number' => $registration['vat_number'],
                'tin' => $registration['tin'],
                'company_registration_number' => $registration['company_registration_number'] ?? null,
                'legal_name' => $registration['legal_name'],
                'trading_name' => $registration['trading_name'] ?? null,
                'taxpayer_type' => $registration['taxpayer_type'],
                'return_frequency' => $registration['return_frequency'],
                'address' => $registration['address'],
                'email' => $registration['email'],
                'status' => 'PENDING_VERIFICATION',
                'verification_source' => 'ITAS',
                'submitted_by' => $actor->id,
                'submitted_at' => $now,
            ]);

            RegistrationVerification::create([
                'id' => (string) Str::uuid(),
                'registration_application_id' => $application->id,
                'provider' => 'ITAS',
                'request_reference' => $verificationReference,
                'status' => $verificationStatus,
                'response_hash' => $responseHash,
                'checked_at' => $checkedAt,
                'expires_at' => $expiresAt,
            ]);

            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'REGISTRATION', 'aggregate_id' => $application->id,
                'event_type' => 'TaxpayerRegistrationSubmitted', 'event_version' => 1, 'partition_key' => $registration['vat_number'],
                'payload' => AuditService::canonicalJson(['registration_id' => $application->id, 'status' => 'PENDING_VERIFICATION', 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);

            AuditService::append($actor, 'TAXPAYER_REGISTRATION_SUBMITTED', 'REGISTRATION', $application->id, [
                'registrationId' => $application->id, 'vatNumber' => $registration['vat_number'],
                'correlationId' => $correlationId, 'verificationState' => $verificationStatus,
            ], $now);

            $application->verification_status = $verificationStatus;
            return $application;
        });
    }

    /** @return array{registrationId: string, status: string, taxpayerId: ?string, organisationId: ?string} */
    public function decide(User $actor, string $registrationId, string $decision, string $reason, string $correlationId): array
    {
        $registration = RegistrationApplication::find($registrationId);
        if (! $registration) {
            throw ValidationException::withMessages(['registration_id' => 'The registration application does not exist.']);
        }
        if (! in_array($registration->status, ['PENDING_VERIFICATION', 'UNDER_REVIEW', 'VERIFIED'], true)) {
            throw ValidationException::withMessages(['status' => "The registration application is already {$registration->status}."]);
        }
        if ($actor->id === $registration->submitted_by) {
            throw ValidationException::withMessages(['actor' => 'The submitting user cannot decide their own registration application.']);
        }

        $now = now();

        if ($decision === 'REJECT') {
            return DB::transaction(function () use ($registration, $reason, $actor, $now, $correlationId) {
                $registration->update(['status' => 'REJECTED', 'reviewed_at' => $now, 'review_reason' => $reason]);
                RegistrationVerification::create([
                    'id' => (string) Str::uuid(), 'registration_application_id' => $registration->id,
                    'provider' => 'MANUAL_REVIEW', 'request_reference' => "manual:{$registration->id}",
                    'status' => 'REJECTED', 'checked_at' => $now,
                ]);
                OutboxEvent::create([
                    'id' => (string) Str::uuid(), 'aggregate_type' => 'REGISTRATION', 'aggregate_id' => $registration->id,
                    'event_type' => 'TaxpayerRegistrationRejected', 'event_version' => 1, 'partition_key' => $registration->vat_number,
                    'payload' => AuditService::canonicalJson(['registration_id' => $registration->id, 'reason' => $reason, 'correlation_id' => $correlationId]),
                    'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
                ]);
                AuditService::append($actor, 'TAXPAYER_REGISTRATION_REJECTED', 'REGISTRATION', $registration->id, ['reason' => $reason], $now);

                return ['registrationId' => $registration->id, 'status' => 'REJECTED', 'taxpayerId' => null, 'organisationId' => null];
            });
        }

        $conflict = Taxpayer::where('vat_number', $registration->vat_number)->orWhere('tin', $registration->tin)->exists()
            || TaxpayerIdentifier::where(function ($q) use ($registration) {
                $q->where('identifier_type', 'VAT_NUMBER')->where('identifier_value', $registration->vat_number);
            })->orWhere(function ($q) use ($registration) {
                $q->where('identifier_type', 'TIN')->where('identifier_value', $registration->tin);
            })->exists();
        if ($conflict) {
            throw new RepositoryConflictException('A canonical taxpayer already exists for this VAT number or TIN.');
        }

        return DB::transaction(function () use ($registration, $reason, $actor, $now, $correlationId) {
            $taxpayer = Taxpayer::create([
                'id' => (string) Str::uuid(), 'vat_number' => $registration->vat_number, 'tin' => $registration->tin,
                'legal_name' => $registration->legal_name, 'trading_name' => $registration->trading_name,
                'taxpayer_type' => $registration->taxpayer_type, 'vat_status' => 'ACTIVE',
                'return_frequency' => $registration->return_frequency, 'address' => $registration->address,
                'email' => $registration->email, 'created_at' => $now,
            ]);

            TaxpayerIdentifier::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'identifier_type' => 'VAT_NUMBER', 'identifier_value' => $registration->vat_number, 'country' => 'NA', 'status' => 'ACTIVE', 'source' => 'MANUAL_REVIEW', 'verified_at' => $now, 'created_at' => $now, 'effective_from' => $now]);
            TaxpayerIdentifier::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'identifier_type' => 'TIN', 'identifier_value' => $registration->tin, 'country' => 'NA', 'status' => 'ACTIVE', 'source' => 'MANUAL_REVIEW', 'verified_at' => $now, 'created_at' => $now, 'effective_from' => $now]);

            $organisation = Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $registration->legal_name, 'trading_name' => $registration->trading_name, 'status' => 'ACTIVE']);

            $branch = Branch::create(['id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'HEAD', 'name' => "{$registration->legal_name} Head Office", 'address' => $registration->address, 'status' => 'ACTIVE', 'is_head_office' => true]);

            OrganisationCapability::create(['id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'BUYER', 'status' => 'ACTIVE', 'effective_from' => $now, 'approved_by' => $actor->id, 'created_at' => $now]);
            OrganisationCapability::create(['id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'SELLER', 'status' => 'ACTIVE', 'effective_from' => $now, 'approved_by' => $actor->id, 'created_at' => $now]);

            OrganisationMembership::create(['id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $registration->submitted_by, 'role_code' => 'TAXPAYER_OWNER', 'branch_id' => $branch->id, 'status' => 'ACTIVE', 'valid_from' => $now, 'assigned_by' => $actor->id, 'created_at' => $now]);

            // Matches the source's own guard: only promotes the submitter if they have no taxpayer yet.
            User::where('id', $registration->submitted_by)->whereNull('taxpayer_id')->update(['role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id]);

            $registration->update(['status' => 'APPROVED', 'reviewed_at' => $now, 'review_reason' => $reason]);

            RegistrationVerification::create([
                'id' => (string) Str::uuid(), 'registration_application_id' => $registration->id,
                'provider' => 'MANUAL_REVIEW', 'request_reference' => "manual:{$registration->id}",
                'status' => 'VERIFIED', 'verified_taxpayer_id' => $taxpayer->id, 'checked_at' => $now,
            ]);

            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'ORGANISATION', 'aggregate_id' => $organisation->id,
                'event_type' => 'OrganisationActivated', 'event_version' => 1, 'partition_key' => $registration->vat_number,
                'payload' => AuditService::canonicalJson(['organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id, 'registration_id' => $registration->id, 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);

            AuditService::append($actor, 'TAXPAYER_REGISTRATION_APPROVED', 'REGISTRATION', $registration->id, ['reason' => $reason, 'taxpayerId' => $taxpayer->id, 'organisationId' => $organisation->id], $now);

            return ['registrationId' => $registration->id, 'status' => 'APPROVED', 'taxpayerId' => $taxpayer->id, 'organisationId' => $organisation->id];
        });
    }
}
