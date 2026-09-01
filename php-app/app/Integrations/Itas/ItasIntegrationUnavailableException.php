<?php

namespace App\Integrations\Itas;

class ItasIntegrationUnavailableException extends \RuntimeException
{
    public function __construct(string $capability = 'taxpayer verification')
    {
        parent::__construct("ITAS {$capability} is awaiting a confirmed technical contract and is not available in this environment.");
    }
}
