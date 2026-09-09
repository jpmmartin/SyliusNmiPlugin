<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recorder;

use Sylius\Component\Core\Model\PaymentInterface;

interface NmiGatewayNoticeRecorderInterface
{
    /**
     * Writes down something the gateway reported that an operator has to see, and answers whether
     * it was new.
     *
     * The reference is the gateway's own handle — a batch identifier, a chargeback identifier —
     * and it is what makes recording the same thing twice a no-op.
     */
    public function record(
        string $type,
        ?string $reference,
        string $paymentMethodCode,
        ?PaymentInterface $payment = null,
        ?int $amount = null,
        ?string $currencyCode = null,
        ?string $reason = null,
    ): bool;
}
