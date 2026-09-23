<?php

namespace App\Domain\Business;

use App\Exceptions\BusinessValidationException;

/**
 * Direct port of lib/domain/counterparty-trust.ts in full --
 * normalizeSyntheticCounterpartyVerification() and
 * evaluateCounterpartyTrust(). Kept as its own small domain class next to
 * App\Domain\Business\BusinessValidator (rather than folded into it)
 * because the source itself keeps counterparty-trust.ts as a separate
 * module from business.ts -- this mirrors that same seam.
 *
 * See 05-security/issue3-counterparty-trust-boundary.md for why this
 * exists at all: `SYNTHETIC_VALID` is the only trust status this port (or
 * the source) can ever produce today -- `AUTHORITY_VERIFIED` requires the
 * NamRA/ITAS/BIPA provider integration, which is
 * `BLOCKED -- EXTERNAL DEPENDENCY REQUIRED` in production. The evaluator
 * itself is provider-agnostic: it reconciles whatever "authority record"
 * it is given against the party's own stored identifiers, regardless of
 * whether that record came from a real provider or (today, exclusively)
 * the labelled synthetic replay this class's own validate() feeds it.
 */
class CounterpartyTrustEvaluator
{
    private const IDENTIFIER_PATTERN = '/^[A-Z0-9][A-Z0-9 ._\/-]{1,39}$/u';

    private const TAX_REGISTRATION_STATUSES = ['ACTIVE', 'INACTIVE', 'SUSPENDED', 'CANCELLED', 'NOT_REGISTERED'];

    /**
     * Ported from normalizeSyntheticCounterpartyVerification(). Throws
     * BusinessValidationException (a single VALIDATION_FAILED-shaped
     * error, matching the source's own CounterpartyTrustValidationError
     * being a single message, not a list) on any failure.
     *
     * @return array{schema_version: string, authority_record: array{legal_name: string, vat_number: ?string, tin: ?string, company_registration_number: ?string, tax_registration_status: string}}
     */
    public static function normalizeSyntheticVerification(array $input): array
    {
        if (($input['schema_version'] ?? null) !== '1.0.0') {
            self::fail('schema_version must be 1.0.0.', '/schema_version');
        }
        $authority = $input['authority_record'] ?? null;
        if (! is_array($authority) || array_is_list($authority)) {
            self::fail('authority_record is required.', '/authority_record');
        }
        /** @var array<string, mixed> $authority */
        $taxStatus = is_string($authority['tax_registration_status'] ?? null) ? mb_strtoupper(trim($authority['tax_registration_status'])) : '';
        if (! in_array($taxStatus, self::TAX_REGISTRATION_STATUSES, true)) {
            self::fail('A supported synthetic tax-registration status is required.', '/authority_record/tax_registration_status');
        }
        $vatNumber = self::identifier($authority['vat_number'] ?? null, '/authority_record/vat_number');
        $tin = self::identifier($authority['tin'] ?? null, '/authority_record/tin');
        $companyRegistrationNumber = self::identifier($authority['company_registration_number'] ?? null, '/authority_record/company_registration_number');
        if (! $vatNumber && ! $tin && ! $companyRegistrationNumber) {
            self::fail('The synthetic authority record requires at least one tax or company identifier.', '/authority_record');
        }

        return [
            'schema_version' => '1.0.0',
            'authority_record' => [
                'legal_name' => self::legalName($authority['legal_name'] ?? null),
                'vat_number' => $vatNumber, 'tin' => $tin, 'company_registration_number' => $companyRegistrationNumber,
                'tax_registration_status' => $taxStatus,
            ],
        ];
    }

    /**
     * Ported from evaluateCounterpartyTrust(). `$party` is
     * {legal_name, vat_number, tin, company_registration_number} (already
     * the raw, un-normalized stored values -- this method normalizes both
     * sides itself, matching the source exactly).
     *
     * @param  array{legal_name: ?string, vat_number: ?string, tin: ?string, company_registration_number: ?string}  $party
     * @param  array{legal_name: string, vat_number: ?string, tin: ?string, company_registration_number: ?string, tax_registration_status: string}  $authority
     * @return array{trust_status: string, tax_registration_status: string, vat_verification_status: string, tin_verification_status: string, company_verification_status: string, confidence_bps: int, matched_fields: list<string>, conflicting_fields: list<string>, reason_code: string}
     */
    public static function evaluate(array $party, array $authority): array
    {
        $matchedFields = [];
        $conflictingFields = [];
        $confidenceBps = 0;

        $compare = function (?string $partyValue, ?string $authorityValue, string $field, int $weight) use (&$matchedFields, &$conflictingFields, &$confidenceBps): string {
            $left = $partyValue !== null ? mb_strtoupper(trim($partyValue)) : '';
            $right = $authorityValue !== null ? mb_strtoupper(trim($authorityValue)) : '';
            if ($left === '') {
                return 'NOT_PROVIDED';
            }
            if ($right === '') {
                $conflictingFields[] = $field;

                return 'INVALID';
            }
            if ($left === $right) {
                $matchedFields[] = $field;
                $confidenceBps += $weight;

                return 'MATCHED';
            }
            $conflictingFields[] = $field;

            return 'MISMATCH';
        };

        $vatVerificationStatus = $compare($party['vat_number'] ?? null, $authority['vat_number'] ?? null, 'vat_number', 4_000);
        $tinVerificationStatus = $compare($party['tin'] ?? null, $authority['tin'] ?? null, 'tin', 3_500);
        $companyVerificationStatus = $compare($party['company_registration_number'] ?? null, $authority['company_registration_number'] ?? null, 'company_registration_number', 1_500);

        $partyName = self::comparableName($party['legal_name'] ?? null);
        if ($partyName !== '' && $partyName === self::comparableName($authority['legal_name'] ?? null)) {
            $matchedFields[] = 'legal_name';
            $confidenceBps += 1_000;
        } else {
            $conflictingFields[] = 'legal_name';
        }

        $identifierMatches = count(array_filter($matchedFields, fn (string $field) => $field !== 'legal_name'));

        if (count($conflictingFields) > 0) {
            $trustStatus = 'MISMATCH';
            $reasonCode = 'COUNTERPARTY_AUTHORITY_MISMATCH';
        } elseif ($identifierMatches === 0) {
            $trustStatus = 'INVALID';
            $reasonCode = 'COUNTERPARTY_IDENTIFIER_REQUIRED';
        } else {
            $trustStatus = 'SYNTHETIC_VALID';
            $reasonCode = 'SYNTHETIC_COUNTERPARTY_MATCH';
        }

        return [
            'trust_status' => $trustStatus, 'tax_registration_status' => $authority['tax_registration_status'],
            'vat_verification_status' => $vatVerificationStatus, 'tin_verification_status' => $tinVerificationStatus,
            'company_verification_status' => $companyVerificationStatus, 'confidence_bps' => $confidenceBps,
            'matched_fields' => array_values($matchedFields), 'conflicting_fields' => array_values($conflictingFields),
            'reason_code' => $reasonCode,
        ];
    }

    private static function identifier(mixed $value, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            self::fail('Authority identifiers must be strings.', $path);
        }
        $normalized = mb_strtoupper(trim($value));
        if (! preg_match(self::IDENTIFIER_PATTERN, $normalized)) {
            self::fail('Authority identifiers contain unsupported characters.', $path);
        }

        return $normalized;
    }

    private static function legalName(mixed $value): string
    {
        if (! is_string($value)) {
            self::fail('Authority legal name is required.', '/authority_record/legal_name');
        }
        $normalized = trim(preg_replace('/\s+/u', ' ', \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value));
        if (mb_strlen($normalized) < 2 || mb_strlen($normalized) > 200) {
            self::fail('Authority legal name must contain 2 to 200 characters.', '/authority_record/legal_name');
        }

        return $normalized;
    }

    private static function comparableName(?string $value): string
    {
        $normalized = \Normalizer::normalize($value ?? '', \Normalizer::FORM_KC) ?: ($value ?? '');
        $normalized = mb_strtoupper($normalized);
        $normalized = preg_replace('/[^A-Z0-9]+/u', ' ', $normalized);

        return trim(preg_replace('/\s+/u', ' ', $normalized));
    }

    private static function fail(string $message, string $path): never
    {
        throw new BusinessValidationException([['code' => 'COUNTERPARTY_TRUST_VALIDATION_FAILED', 'path' => $path, 'message' => $message]]);
    }
}
