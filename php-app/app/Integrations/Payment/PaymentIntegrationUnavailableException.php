<?php

namespace App\Integrations\Payment;

/**
 * Ported from lib/integrations/payment.ts's PaymentIntegrationUnavailableError.
 * Unlike App\Exceptions\PaymentResourceException/PaymentValidationException,
 * this never reaches the HTTP layer -- App\Services\Payment\PaymentService
 * always catches it internally and reports a soft "AWAITING_AUTHORITY"
 * outcome instead (see that class's own doc comment), so it carries no
 * render() method.
 */
class PaymentIntegrationUnavailableException extends \RuntimeException
{
    public function __construct(string $capability = 'recording')
    {
        parent::__construct("Payment {$capability} is DISABLED PENDING AUTHORITY and is not available in this environment.");
    }
}
