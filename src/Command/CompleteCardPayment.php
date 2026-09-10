<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Second phase: charge the token the browser posted. Whether that is a sale or an
 * authorisation is not a property of this message — the request's own action already says
 * which, chosen by the platform from the payment method's configuration.
 *
 * @internal
 */
final class CompleteCardPayment implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
