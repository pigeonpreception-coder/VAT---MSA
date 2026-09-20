<?php

namespace App\Integrations\Payment;

/**
 * Ported from lib/integrations/payment.ts's PaymentConnectorPort, mirroring
 * App\Integrations\Itas\ItasIdentityPort's shape (typed port + an adapter)
 * with one deliberate structural difference. ITAS's adapter is a
 * hardcoded, environment-agnostic "always unavailable" class -- there is
 * no configuration anywhere that could ever make it available. Payment is
 * different: the source's own playbook explicitly calls for "a sandbox/mock
 * implementation," not just a typed stub, so App\Integrations\Payment\
 * SandboxPaymentConnector below contains real (if trivial) mock logic --
 * but every mutating method re-reads its authorisation state from
 * `service_components` (component_key='PAYMENT_CONNECTOR') on every single
 * call before doing anything else, rather than trusting a constructor-time
 * or cached flag. That row is seeded DISABLED (see database/seeders/
 * ServiceComponentSeeder) and nothing anywhere in this codebase ever
 * writes to it -- this is the one genuine runtime *enforcement* use of
 * service_components, not just a display row.
 */
interface PaymentConnectorPort
{
    /** @return array{provider: string, configured: bool, state: string, capabilities: list<string>} */
    public function status(): array;

    /**
     * @param array{request_reference: string, refund_claim_id: string, taxpayer_id: string, amount_cents: int, currency: string, beneficiary_reference_masked: string, provider: string, correlation_id: string} $request
     * @return array{provider_reference: string, status: string, response_hash: string, submitted_at: string}
     *
     * @throws PaymentIntegrationUnavailableException
     */
    public function recordPayment(array $request): array;

    /**
     * @param array{request_reference: string, payment_instruction_id: string, settlement_reference: string, settled_amount_cents: int, correlation_id: string} $request
     * @return array{settlement_reference: string, status: string, response_hash: string, settled_at: string}
     *
     * @throws PaymentIntegrationUnavailableException
     */
    public function allocatePayment(array $request): array;
}
