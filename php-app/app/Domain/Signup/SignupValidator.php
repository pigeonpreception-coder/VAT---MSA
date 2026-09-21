<?php

namespace App\Domain\Signup;

use App\Exceptions\SignupAuthorityRequiredException;
use App\Exceptions\SignupValidationException;

/**
 * Direct port of lib/domain/signup.ts's normalizeAndValidateSelfServeSignup,
 * folding in lib/domain/identity.ts's normalizeAndValidateRegistration for
 * the shared taxpayer-identity fields exactly as source does (a single
 * call passing vat_number/tin/company_registration_number/legal_name/
 * trading_name/taxpayer_type/return_frequency/address/contact_email
 * through). Pure validation, no DB access.
 */
class SignupValidator
{
    private const IDENTIFIER_PATTERN = '/^[A-Z0-9][A-Z0-9.\/-]{2,39}$/';
    private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
    private const APPLICANT_ROLES = ['OWNER', 'DIRECTOR', 'PARTNER', 'TRUSTEE', 'AUTHORISED_REPRESENTATIVE'];
    private const PLAN_CODE_PATTERN = '/^[A-Z][A-Z0-9_]{2,39}$/';
    private const TAXPAYER_TYPES = ['PRIVATE_COMPANY', 'CLOSE_CORPORATION', 'SOLE_PROPRIETOR', 'PARTNERSHIP', 'TRUST', 'NON_PROFIT', 'PUBLIC_ENTITY', 'OTHER'];
    private const RETURN_FREQUENCIES = ['MONTHLY', 'BIMONTHLY', 'QUARTERLY', 'ANNUAL'];
    private const ALLOWED_FIELDS = [
        'schema_version', 'applicant_name', 'applicant_role', 'contact_email', 'country_code', 'plan_code',
        'vat_number', 'tin', 'company_registration_number', 'legal_name', 'trading_name', 'taxpayer_type',
        'return_frequency', 'address', 'company_system_administrator_attested', 'terms_accepted', 'privacy_notice_accepted',
    ];

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function boundedText(mixed $value, string $label, int $min, int $max): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', self::text($value)));
        if (mb_strlen($normalized) < $min || mb_strlen($normalized) > $max) {
            throw new SignupValidationException('FIELD_LENGTH_INVALID', "{$label} must contain {$min} to {$max} characters.");
        }

        return $normalized;
    }

    private static function identifier(mixed $value, string $label): string
    {
        $normalized = mb_strtoupper(self::text($value));
        if (! preg_match(self::IDENTIFIER_PATTERN, $normalized)) {
            throw new SignupValidationException('IDENTIFIER_INVALID', "{$label} must contain 3 to 40 letters, numbers, dots, slashes or hyphens.");
        }

        return $normalized;
    }

    /**
     * @return array{schema_version: string, applicant_name: string, applicant_role: string, contact_email: string, country_code: string, plan_code: string, vat_number: string, tin: string, company_registration_number: ?string, legal_name: string, trading_name: ?string, taxpayer_type: string, return_frequency: string, address: string, company_system_administrator_attested: true, terms_accepted: true, privacy_notice_accepted: true}
     */
    public static function submission(array $payload): array
    {
        foreach (array_keys($payload) as $field) {
            if (! in_array($field, self::ALLOWED_FIELDS, true)) {
                throw new SignupValidationException('FIELD_UNEXPECTED', 'This field is not accepted.');
            }
        }

        // Checked first and unconditionally -- see
        // SignupAuthorityRequiredException's own doc comment for why this
        // one field is validated ahead of, and independently from, every
        // other field below.
        if (($payload['company_system_administrator_attested'] ?? null) !== true) {
            throw new SignupAuthorityRequiredException();
        }

        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new SignupValidationException('SCHEMA_VERSION_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }

        $applicantName = self::boundedText($payload['applicant_name'] ?? null, 'Applicant name', 2, 120);
        $applicantRole = mb_strtoupper(self::text($payload['applicant_role'] ?? null));
        if (! in_array($applicantRole, self::APPLICANT_ROLES, true)) {
            throw new SignupValidationException('APPLICANT_ROLE_INVALID', 'Select a supported applicant authority.');
        }
        $planCode = mb_strtoupper(self::text($payload['plan_code'] ?? null));
        if (! preg_match(self::PLAN_CODE_PATTERN, $planCode)) {
            throw new SignupValidationException('PLAN_CODE_INVALID', 'Select an available licence plan.');
        }
        if (mb_strtoupper(self::text($payload['country_code'] ?? null)) !== 'NA') {
            throw new SignupValidationException('COUNTRY_UNSUPPORTED', 'This controlled signup channel currently accepts Namibia applications only.');
        }
        if (($payload['terms_accepted'] ?? null) !== true) {
            throw new SignupValidationException('TERMS_ACCEPTANCE_REQUIRED', 'Accept the current terms to continue.');
        }
        if (($payload['privacy_notice_accepted'] ?? null) !== true) {
            throw new SignupValidationException('PRIVACY_NOTICE_ACCEPTANCE_REQUIRED', 'Acknowledge the current privacy notice to continue.');
        }

        // lib/domain/identity.ts's normalizeAndValidateRegistration, folded
        // in exactly as source's own normalizeAndValidateSelfServeSignup does.
        $vatNumber = self::identifier($payload['vat_number'] ?? null, 'VAT number');
        $tin = self::identifier($payload['tin'] ?? null, 'TIN');
        $companyRegistrationNumber = self::text($payload['company_registration_number'] ?? null) !== ''
            ? self::identifier($payload['company_registration_number'], 'Company registration number') : null;
        $legalName = self::boundedText($payload['legal_name'] ?? null, 'Legal name', 2, 200);
        $tradingName = self::text($payload['trading_name'] ?? null) !== ''
            ? self::boundedText($payload['trading_name'], 'Trading name', 2, 200) : null;
        $address = self::boundedText($payload['address'] ?? null, 'Address', 5, 500);
        $contactEmail = mb_strtolower(self::text($payload['contact_email'] ?? null));
        if (mb_strlen($contactEmail) > 254 || ! preg_match(self::EMAIL_PATTERN, $contactEmail)) {
            throw new SignupValidationException('EMAIL_INVALID', 'A valid contact email address is required.');
        }
        $taxpayerType = mb_strtoupper(self::text($payload['taxpayer_type'] ?? null));
        if (! in_array($taxpayerType, self::TAXPAYER_TYPES, true)) {
            throw new SignupValidationException('TAXPAYER_TYPE_INVALID', 'Select a supported taxpayer type.');
        }
        $returnFrequency = mb_strtoupper(self::text($payload['return_frequency'] ?? null));
        if (! in_array($returnFrequency, self::RETURN_FREQUENCIES, true)) {
            throw new SignupValidationException('RETURN_FREQUENCY_INVALID', 'Select a supported return frequency.');
        }
        if ($vatNumber === $tin) {
            throw new SignupValidationException('IDENTIFIERS_NOT_DISTINCT', 'VAT number and TIN must be distinct identifiers.');
        }

        return [
            'schema_version' => '1.0.0', 'applicant_name' => $applicantName, 'applicant_role' => $applicantRole,
            'contact_email' => $contactEmail, 'country_code' => 'NA', 'plan_code' => $planCode,
            'vat_number' => $vatNumber, 'tin' => $tin, 'company_registration_number' => $companyRegistrationNumber,
            'legal_name' => $legalName, 'trading_name' => $tradingName, 'taxpayer_type' => $taxpayerType,
            'return_frequency' => $returnFrequency, 'address' => $address,
            'company_system_administrator_attested' => true, 'terms_accepted' => true, 'privacy_notice_accepted' => true,
        ];
    }
}
