<?php

namespace App\Integrations\Etariff;

class UnavailableEtariffAdapter implements EtariffPort
{
    public function status(): array
    {
        return [
            'provider' => 'ETARIFF',
            'configured' => false,
            'state' => 'REQUIRES_ETARIFF_CONFIRMATION',
            'capabilities' => ['FOREIGN_INVOICE_PULL'],
        ];
    }

    public function pullDeclarations(array $request): array
    {
        throw new EtariffIntegrationUnavailableException();
    }
}
