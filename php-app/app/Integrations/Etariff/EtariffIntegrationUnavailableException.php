<?php

namespace App\Integrations\Etariff;

class EtariffIntegrationUnavailableException extends \RuntimeException
{
    public function __construct(string $capability = 'foreign invoice declaration pull')
    {
        parent::__construct("E-Tariff {$capability} is awaiting a confirmed technical contract with NamRA's border/customs systems and is not available in this environment.");
    }
}
