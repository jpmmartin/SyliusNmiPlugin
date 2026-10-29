<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * What a request naming `refund` or `cancel` becomes when it is announced rather than sent by this
 * plugin — through the shop API, say: a request that fails, and money that does not move.
 *
 * @internal
 */
final class RefuseRefundOrVoid implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
