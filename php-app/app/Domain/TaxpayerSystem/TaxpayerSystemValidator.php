<?php

namespace App\Domain\TaxpayerSystem;

use App\Exceptions\TaxpayerSystemValidationException;

/**
 * Direct port of lib/domain/taxpayer-system.ts's
 * validateTaxpayerSystemRegistration/validateTaxpayerSystemSuspension/
 * validateTaxpayerSystemSync/assertTaxpayerSystemTransition -- pure
 * validation and the registration lifecycle state machine for a
 * taxpayer's own ERP/POS/accounting/invoicing system, no DB access.
 * Identifier format regex matches lib/domain/business.ts's existing
 * vat_number/tin/company_registration_number pattern rather than a
 * stricter Namibian-specific format -- the source itself stays
 * deliberately loose pending regulatory confirmation, reproduced as-is.
 */
class TaxpayerSystemValidator
{
    private const IDENTIFIER_PATTERN = '/^[A-Z0-9][A-Z0-9 ._\/-]{1,39}$/';
    private const SYSTEM_CATEGORIES = ['ERP', 'POS', 'ACCOUNTING', 'INVOICING', 'OTHER'];
    private const API_STATUSES = ['CONNECTED', 'DEGRADED', 'DISCONNECTED'];

    /** @var array<string, array<string, string>> */
    private const TRANSITIONS = [
        'DRAFT' => ['APPROVE' => 'APPROVED'],
        'APPROVED' => ['SUSPEND' => 'SUSPENDED'],
        'SUSPENDED' => ['APPROVE' => 'APPROVED'],
    ];

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }

    private static function bounded(mixed $value, string $path, string $label, int $min, int $max): string
    {
        $normalized = self::text($value);
        if (mb_strlen($normalized) < $min || mb_strlen($normalized) > $max) {
            throw new TaxpayerSystemValidationException('FIELD_LENGTH_INVALID', "{$label} must contain {$min} to {$max} characters.");
        }

        return $normalized;
    }

    /** Like bounded(), but absent entirely is fine -- only a present-and-too-short/too-long value is rejected. */
    private static function optionalBounded(mixed $value, string $path, string $label, int $min, int $max): ?string
    {
        $normalized = self::text($value);
        if ($normalized === '') {
            return null;
        }
        if (mb_strlen($normalized) < $min || mb_strlen($normalized) > $max) {
            throw new TaxpayerSystemValidationException('FIELD_LENGTH_INVALID', "{$label} must contain {$min} to {$max} characters.");
        }

        return $normalized;
    }

    private static function assertSchema(array $payload): void
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new TaxpayerSystemValidationException('SCHEMA_VERSION_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
    }

    /**
     * RegisterTaxpayerSystem. Identity fields are cross-checked against
     * the actor's own resolved organisation/taxpayer in the service layer
     * -- this method only validates shape.
     *
     * @return array{schema_version: string, vat_registration_number: string, tin: ?string, company_registration_number: ?string, system_name: string, system_vendor: string, system_category: string, credential_reference: ?string}
     */
    public static function registration(array $payload): array
    {
        self::assertSchema($payload);
        $vatRegistrationNumber = mb_strtoupper(self::bounded($payload['vat_registration_number'] ?? null, '/vat_registration_number', 'VAT registration number', 1, 40));
        if (! preg_match(self::IDENTIFIER_PATTERN, $vatRegistrationNumber)) {
            throw new TaxpayerSystemValidationException('VAT_REGISTRATION_NUMBER_INVALID', 'VAT registration number contains unsupported characters.');
        }
        $tin = self::optionalBounded($payload['tin'] ?? null, '/tin', 'TIN', 1, 40);
        $tin = $tin !== null ? mb_strtoupper($tin) : null;
        if ($tin !== null && ! preg_match(self::IDENTIFIER_PATTERN, $tin)) {
            throw new TaxpayerSystemValidationException('TIN_INVALID', 'TIN contains unsupported characters.');
        }
        $companyRegistrationNumber = self::optionalBounded($payload['company_registration_number'] ?? null, '/company_registration_number', 'Company registration number', 1, 40);
        $companyRegistrationNumber = $companyRegistrationNumber !== null ? mb_strtoupper($companyRegistrationNumber) : null;
        if ($companyRegistrationNumber !== null && ! preg_match(self::IDENTIFIER_PATTERN, $companyRegistrationNumber)) {
            throw new TaxpayerSystemValidationException('COMPANY_REGISTRATION_NUMBER_INVALID', 'Company registration number contains unsupported characters.');
        }
        $systemName = self::bounded($payload['system_name'] ?? null, '/system_name', 'System name', 2, 150);
        $systemVendor = self::bounded($payload['system_vendor'] ?? null, '/system_vendor', 'System vendor', 2, 150);
        $systemCategory = mb_strtoupper(self::text($payload['system_category'] ?? null));
        if (! in_array($systemCategory, self::SYSTEM_CATEGORIES, true)) {
            throw new TaxpayerSystemValidationException('SYSTEM_CATEGORY_INVALID', 'system_category must be one of: '.implode(', ', self::SYSTEM_CATEGORIES).'.');
        }
        $credentialReference = self::optionalBounded($payload['credential_reference'] ?? null, '/credential_reference', 'Credential reference', 3, 300);

        return [
            'schema_version' => '1.0.0', 'vat_registration_number' => $vatRegistrationNumber, 'tin' => $tin,
            'company_registration_number' => $companyRegistrationNumber, 'system_name' => $systemName,
            'system_vendor' => $systemVendor, 'system_category' => $systemCategory, 'credential_reference' => $credentialReference,
        ];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function suspension(array $payload): array
    {
        self::assertSchema($payload);
        $reason = self::bounded($payload['reason'] ?? null, '/reason', 'Reason', 10, 500);

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    /**
     * RecordSynchronization: the taxpayer's own system reports its
     * post-sync connectivity state (never CONNECTED-before-any-sync --
     * that only happens through this command, not at registration).
     *
     * @return array{schema_version: string, api_status: string}
     */
    public static function sync(array $payload): array
    {
        self::assertSchema($payload);
        $apiStatus = mb_strtoupper(self::text($payload['api_status'] ?? null));
        if (! in_array($apiStatus, self::API_STATUSES, true)) {
            throw new TaxpayerSystemValidationException('API_STATUS_INVALID', 'api_status must be one of: '.implode(', ', self::API_STATUSES).'.');
        }

        return ['schema_version' => '1.0.0', 'api_status' => $apiStatus];
    }

    public static function assertTransition(string $action, string $current): string
    {
        $target = self::TRANSITIONS[$current][$action] ?? null;
        if ($target === null) {
            throw new TaxpayerSystemValidationException('TAXPAYER_SYSTEM_TRANSITION_INVALID', 'Cannot '.mb_strtolower($action)." a registration currently {$current}.");
        }

        return $target;
    }
}
