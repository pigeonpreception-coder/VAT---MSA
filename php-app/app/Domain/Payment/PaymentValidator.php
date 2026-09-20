<?php

namespace App\Domain\Payment;

use App\Exceptions\PaymentValidationException;

/**
 * Direct port of lib/domain/payment.ts's
 * validateRecordPaymentInput/validateAllocatePaymentInput/maskBeneficiaryReference
 * -- pure validation for the Payment domain's two mutating commands, no DB
 * access, no connector awareness.
 */
class PaymentValidator
{
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }

    private static function bounded(mixed $value, string $path, string $label, int $min, int $max): string
    {
        $normalized = self::text($value);
        if (mb_strlen($normalized) < $min || mb_strlen($normalized) > $max) {
            throw new PaymentValidationException('FIELD_LENGTH_INVALID', "{$label} must contain {$min} to {$max} characters.");
        }

        return $normalized;
    }

    private static function assertSchema(array $payload): void
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new PaymentValidationException('SCHEMA_VERSION_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
    }

    /**
     * RecordPayment: no amount field -- the amount is always the claim's
     * own net_payable_cents, computed server-side at PAYMENT_AUTHORISATION,
     * never taken from client input.
     *
     * @return array{schema_version: string, beneficiary_reference: string, provider: string}
     */
    public static function recordPaymentInput(array $payload): array
    {
        self::assertSchema($payload);
        $beneficiaryReference = self::bounded($payload['beneficiary_reference'] ?? null, '/beneficiary_reference', 'Beneficiary reference', 4, 100);
        $provider = self::bounded($payload['provider'] ?? null, '/provider', 'Provider', 2, 60);

        return ['schema_version' => '1.0.0', 'beneficiary_reference' => $beneficiaryReference, 'provider' => $provider];
    }

    /** @return array{schema_version: string, settlement_reference: string, settled_amount_cents: int} */
    public static function allocatePaymentInput(array $payload): array
    {
        self::assertSchema($payload);
        $settlementReference = self::bounded($payload['settlement_reference'] ?? null, '/settlement_reference', 'Settlement reference', 4, 100);
        $settledAmount = filter_var($payload['settled_amount_cents'] ?? null, FILTER_VALIDATE_INT);
        if ($settledAmount === false || $settledAmount <= 0) {
            throw new PaymentValidationException('AMOUNT_INVALID', 'settled_amount_cents must be a positive safe integer.');
        }

        return ['schema_version' => '1.0.0', 'settlement_reference' => $settlementReference, 'settled_amount_cents' => $settledAmount];
    }

    /** Never persist a raw account/beneficiary reference -- only the masked form (last 4 characters visible) ever reaches payment_instructions.beneficiary_reference_masked. */
    public static function maskBeneficiaryReference(string $raw): string
    {
        $trimmed = trim($raw);
        if (mb_strlen($trimmed) <= 4) {
            return str_repeat('*', mb_strlen($trimmed));
        }

        return str_repeat('*', mb_strlen($trimmed) - 4).mb_substr($trimmed, -4);
    }
}
