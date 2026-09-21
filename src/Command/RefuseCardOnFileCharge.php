<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * What a request naming the card-on-file charge's action becomes when it arrives any way but through
 * the charger — through the shop API, say: a request that fails, and a charge that never happens.
 *
 * @internal
 */
final class RefuseCardOnFileCharge implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
