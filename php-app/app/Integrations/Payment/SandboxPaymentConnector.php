<?php

namespace App\Integrations\Payment;

use App\Models\ServiceComponent;
use App\Services\Audit\AuditService;

/**
 * The sandbox/mock implementation the source's own playbook asks for. Its
 * recordPayment/allocatePayment logic below is genuine (deterministic
 * reference and response hash, real status transitions) -- but is provably
 * unreachable today because assertSandboxActive()'s guard runs first on
 * every call and component-payment's seed row never satisfies it. See
 * tests/Feature/Payment/PaymentConnectorTest.php for both halves of that
 * proof: the guard blocking every real command path, and a clearly-labelled
 * direct-DB simulation of a hypothetical SANDBOX_ACTIVE state exercising
 * this class's mock logic in isolation (never via any real command -- no
 * command anywhere can reach that state).
 */
class SandboxPaymentConnector implements PaymentConnectorPort
{
    private function readGuardState(): ?ServiceComponent
    {
        return ServiceComponent::where('component_key', 'PAYMENT_CONNECTOR')->first();
    }

    private function isSandboxActive(?ServiceComponent $state): bool
    {
        return $state?->configuration_status === 'SANDBOX_CONFIGURED' && $state?->operational_status === 'SANDBOX_ACTIVE';
    }

    private function assertSandboxActive(?ServiceComponent $state, string $capability): void
    {
        if (! $this->isSandboxActive($state)) {
            throw new PaymentIntegrationUnavailableException($capability);
        }
    }

    public function status(): array
    {
        $active = $this->isSandboxActive($this->readGuardState());

        return [
            'provider' => 'SANDBOX_PAYMENT_CONNECTOR',
            'configured' => $active,
            'state' => $active ? 'SANDBOX_ACTIVE' : 'REQUIRES_AUTHORITY_CONTRACT',
            'capabilities' => ['RECORD_PAYMENT', 'ALLOCATE_PAYMENT'],
        ];
    }

    public function recordPayment(array $request): array
    {
        $this->assertSandboxActive($this->readGuardState(), 'recording');
        $responseHash = hash('sha256', AuditService::canonicalJson($request));

        return [
            'provider_reference' => "SANDBOX-{$request['request_reference']}", 'status' => 'INITIATED',
            'response_hash' => $responseHash, 'submitted_at' => now()->toISOString(),
        ];
    }

    public function allocatePayment(array $request): array
    {
        $this->assertSandboxActive($this->readGuardState(), 'allocation');
        $responseHash = hash('sha256', AuditService::canonicalJson($request));

        return [
            'settlement_reference' => $request['settlement_reference'], 'status' => 'SETTLED',
            'response_hash' => $responseHash, 'settled_at' => now()->toISOString(),
        ];
    }
}
