<?php

namespace App\Services\Signup;

use App\Domain\Signup\SignupValidator;
use App\Exceptions\RepositoryConflictException;
use App\Exceptions\SignupValidationException;
use App\Models\LicensePlan;
use App\Models\LicensePlanEntitlement;
use App\Models\OutboxEvent;
use App\Models\RegistrationApplication;
use App\Models\SelfServeSignupApplication;
use App\Models\Taxpayer;
use App\Models\TaxpayerIdentifier;
use App\Services\Audit\AuditService;
use App\Support\Security\RateLimitGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/signup-repository.ts's submitSelfServeSignup -- the
 * self-serve commercial SaaS signup channel. Genuinely unauthenticated
 * (the one command in this codebase with no real actor at all), unlike
 * every other module ported this session.
 *
 * Source's `identity` parameter (a "SITES_WORKSPACE" SSO claim from its
 * own ChatGPT Apps integration) has no equivalent anywhere in this port --
 * that integration was never migrated -- so `identity` is always null
 * here: `identity_status` is always VERIFICATION_REQUIRED, never
 * EXTERNALLY_ASSERTED, and the actor hash always derives from
 * contact_email rather than an asserted subject. A documented, deliberate
 * deviation, not an oversight.
 *
 * 2026-09-22 anti-enumeration fix: this used to throw a distinct
 * RepositoryConflictException (HTTP 409, no row written) whenever
 * vat_number/tin already matched an existing taxpayer, an in-progress
 * registration application, or another pending self-serve application --
 * letting an anonymous caller tell a real VAT number/TIN apart from an
 * unused one purely from the response shape of an otherwise perfectly
 * legitimate, correctly-rate-limited request. Every submission that passes
 * validation now gets the identical 202 response and writes a real row
 * either way; a detected conflict is recorded on the row itself
 * (`identity_conflict_detected`) for whoever eventually reviews these
 * applications, not echoed to the caller -- present() below never reads
 * that column. Nothing about a self-serve row is auto-actioned regardless
 * of this flag (no account, payment, subscription or licence is ever
 * activated by one alone -- see present()'s own `next_action` text), so
 * recording rather than rejecting a conflicting claim introduces no new
 * privilege; it only makes a previously silently-dropped signal durable
 * and admin-visible instead of anonymous-caller-visible.
 */
class SignupService
{
    public const TERMS_VERSION = '2026-08-23';
    public const PRIVACY_NOTICE_VERSION = '2026-08-23';

    private const ACTIVE_SIGNUP_STATES = ['PENDING_VERIFICATION', 'UNDER_REVIEW', 'APPROVED_FOR_PROVISIONING'];

    /**
     * Ported from lib/data/signup-repository.ts's listPublicSignupPlans --
     * a genuinely public, unauthenticated read that lets an applicant see
     * plan names/features before submitting, rather than having to already
     * know an exact plan code by heart. Same effective-window/status/
     * plan_domain filter submit() itself uses to validate a submitted
     * plan_code, kept in sync deliberately (both read the identical
     * WHERE shape) rather than factored into a shared private helper,
     * since submit()'s needs a single plan by code+ordered-by-version
     * while this needs every currently-open plan.
     *
     * @return list<array{code: string, name: string, version: int, features: list<string>}>
     */
    public function listPublicPlans(): array
    {
        $now = now();
        $plans = LicensePlan::where('plan_domain', 'COMMERCIAL_SAAS')->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->orderBy('name')->orderByDesc('version')->get();
        if ($plans->isEmpty()) {
            return [];
        }

        $featureNamesByPlan = LicensePlanEntitlement::whereIn('license_plan_id', $plans->pluck('id'))
            ->where('enabled', true)->with('feature')->get()
            ->groupBy('license_plan_id')
            ->map(fn ($entitlements) => $entitlements->pluck('feature.name')->filter()->values()->all());

        return $plans->map(fn (LicensePlan $plan) => [
            'code' => $plan->code, 'name' => $plan->name, 'version' => (int) $plan->version,
            'features' => $featureNamesByPlan->get($plan->id, []),
        ])->values()->all();
    }

    /** @return array{application_reference: string, status: string, identity_status: string, taxpayer_verification_status: string, licence_status: string, submitted_at: string, next_action: string} */
    public function submit(array $payload, string $sourceToken, string $deviceId, string $idempotencyKey): array
    {
        RateLimitGuard::enforceSelfServeSignup($sourceToken, $deviceId);
        $signup = SignupValidator::submission($payload);
        RateLimitGuard::enforceSelfServeSignupEmail($signup['contact_email']);

        if (mb_strlen($idempotencyKey) < 16 || mb_strlen($idempotencyKey) > 128) {
            throw new SignupValidationException('IDEMPOTENCY_KEY_INVALID', 'Idempotency key must contain 16 to 128 characters.');
        }

        $requestHash = hash('sha256', AuditService::canonicalJson([
            'signup' => $signup, 'identity_provider' => null, 'identity_subject_hash' => null,
            'terms_version' => self::TERMS_VERSION, 'privacy_notice_version' => self::PRIVACY_NOTICE_VERSION,
        ]));

        $prior = SelfServeSignupApplication::where('contact_email', $signup['contact_email'])->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            if ($prior->request_hash !== $requestHash) {
                throw new RepositoryConflictException('The idempotency key was already used for a different signup application.');
            }

            return $this->present($prior);
        }

        $now = now();
        $plan = LicensePlan::where('code', $signup['plan_code'])->where('plan_domain', 'COMMERCIAL_SAAS')->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->orderByDesc('version')->first();
        if (! $plan) {
            throw new SignupValidationException('PLAN_UNAVAILABLE', 'The selected licence plan is not currently available for signup.');
        }

        $canonical = Taxpayer::where('vat_number', $signup['vat_number'])->orWhere('tin', $signup['tin'])->exists()
            || TaxpayerIdentifier::where(function ($q) use ($signup) {
                $q->where('identifier_type', 'VAT_NUMBER')->where('identifier_value', $signup['vat_number']);
            })->orWhere(function ($q) use ($signup) {
                $q->where('identifier_type', 'TIN')->where('identifier_value', $signup['tin']);
            })->exists();
        $controlled = ! $canonical && RegistrationApplication::where(function ($q) use ($signup) {
            $q->where('vat_number', $signup['vat_number'])->orWhere('tin', $signup['tin']);
        })->whereIn('status', ['PENDING_VERIFICATION', 'UNDER_REVIEW', 'VERIFIED'])->exists();
        $pending = ! $canonical && ! $controlled && SelfServeSignupApplication::where(function ($q) use ($signup) {
            $q->where('vat_number', $signup['vat_number'])->orWhere('tin', $signup['tin']);
        })->whereIn('status', self::ACTIVE_SIGNUP_STATES)->exists();
        // Not rejected here (that would be the enumeration channel this
        // fix closes) -- recorded on the row instead, below.
        $identityConflict = $canonical || $controlled || $pending;

        $id = (string) Str::uuid();
        $publicReference = 'VMS-'.$now->format('Y').'-'.mb_strtoupper(mb_substr(str_replace('-', '', (string) Str::uuid()), 0, 10));
        $actorId = $this->syntheticActorId($signup['contact_email']);

        DB::transaction(function () use ($id, $publicReference, $signup, $plan, $idempotencyKey, $requestHash, $now, $actorId, $identityConflict, $pending) {
            if ($pending) {
                // The conflict is symmetric: an earlier pending application
                // sharing this vat_number/tin is just as much "in conflict"
                // as the one being written below, so a reviewer scanning
                // for identity_conflict_detected sees both sides, not just
                // whichever application happened to be submitted second.
                SelfServeSignupApplication::where(function ($q) use ($signup) {
                    $q->where('vat_number', $signup['vat_number'])->orWhere('tin', $signup['tin']);
                })->whereIn('status', self::ACTIVE_SIGNUP_STATES)->update(['identity_conflict_detected' => true]);
            }

            SelfServeSignupApplication::create([
                'id' => $id, 'public_reference' => $publicReference, 'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                'applicant_name' => $signup['applicant_name'], 'applicant_role' => $signup['applicant_role'], 'contact_email' => $signup['contact_email'],
                'identity_provider' => null, 'identity_subject_hash' => null, 'onboarding_path' => 'COMPANY_ADMIN', 'country_code' => 'NA',
                'requested_plan_id' => $plan->id, 'vat_number' => $signup['vat_number'], 'tin' => $signup['tin'],
                'company_registration_number' => $signup['company_registration_number'], 'legal_name' => $signup['legal_name'],
                'trading_name' => $signup['trading_name'], 'taxpayer_type' => $signup['taxpayer_type'], 'return_frequency' => $signup['return_frequency'],
                'address' => $signup['address'], 'terms_version' => self::TERMS_VERSION, 'privacy_notice_version' => self::PRIVACY_NOTICE_VERSION,
                'authority_attested_at' => $now, 'terms_accepted_at' => $now, 'privacy_notice_accepted_at' => $now,
                'status' => 'PENDING_VERIFICATION', 'identity_status' => 'VERIFICATION_REQUIRED',
                'taxpayer_verification_status' => 'AWAITING_PROVIDER_CONTRACT', 'identity_conflict_detected' => $identityConflict,
                'licence_status' => 'NOT_ACTIVATED', 'promoted_registration_application_id' => null, 'submitted_at' => $now,
            ]);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'SELF_SERVE_SIGNUP', 'aggregate_id' => $id,
                'event_type' => 'SelfServeSignupSubmitted', 'event_version' => 1, 'partition_key' => $actorId,
                'payload' => AuditService::canonicalJson([
                    'application_reference' => $publicReference, 'requested_plan_code' => $plan->code, 'status' => 'PENDING_VERIFICATION',
                    'identity_status' => 'VERIFICATION_REQUIRED', 'activation_effect' => 'NONE', 'identity_conflict_detected' => $identityConflict,
                ]), 'status' => 'PENDING', 'publish_attempts' => 0, 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::appendSynthetic($actorId, 'SELF_SERVE_APPLICANT', 'SELF_SERVE_SIGNUP_SUBMITTED', 'SELF_SERVE_SIGNUP', $id, [
                'applicationReference' => $publicReference, 'identityStatus' => 'VERIFICATION_REQUIRED',
                'requestedPlanCode' => $plan->code, 'activationEffect' => 'NONE', 'identityConflictDetected' => $identityConflict,
            ], $now);
        });

        return $this->present(SelfServeSignupApplication::find($id));
    }

    private function syntheticActorId(string $contactEmail): string
    {
        $hex = mb_substr(hash('sha256', $contactEmail), -32);

        return implode('-', [mb_substr($hex, 0, 8), mb_substr($hex, 8, 4), mb_substr($hex, 12, 4), mb_substr($hex, 16, 4), mb_substr($hex, 20, 12)]);
    }

    /** @return array{application_reference: string, status: string, identity_status: string, taxpayer_verification_status: string, licence_status: string, submitted_at: string, next_action: string} */
    private function present(SelfServeSignupApplication $application): array
    {
        return [
            'application_reference' => $application->public_reference, 'status' => $application->status,
            'identity_status' => $application->identity_status, 'taxpayer_verification_status' => $application->taxpayer_verification_status,
            'licence_status' => $application->licence_status, 'submitted_at' => optional($application->submitted_at)->toISOString(),
            'next_action' => 'The commercial application is held for administrator and organisation verification. No account, payment, subscription or licence has been activated.',
        ];
    }
}
