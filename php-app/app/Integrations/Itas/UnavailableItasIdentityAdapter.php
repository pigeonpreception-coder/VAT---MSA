<?php

namespace App\Integrations\Itas;

class UnavailableItasIdentityAdapter implements ItasIdentityPort
{
    public function status(): array
    {
        return [
            'provider' => 'ITAS',
            'configured' => false,
            'state' => 'REQUIRES_ITAS_CONFIRMATION',
            'capabilities' => ['IDENTITY_FEDERATION', 'TAXPAYER_VERIFICATION', 'RETURN_SUBMISSION'],
        ];
    }

    public function verifyTaxpayer(array $request): array
    {
        throw new ItasIntegrationUnavailableException('taxpayer verification');
    }
}
