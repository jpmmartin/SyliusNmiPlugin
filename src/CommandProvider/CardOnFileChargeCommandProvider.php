<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiCardOnFileChargerInterface;
use JpmMartin\SyliusNmiPlugin\Command\RefuseCardOnFileCharge;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Answers the card-on-file charge's action with a refusal, always.
 *
 * The charger never announces its requests — it dispatches the charge itself — so the only requests
 * that reach this provider are ones created some other way: a client of the shop API naming the
 * action, which the platform lets it do. Answering them with a refusal, rather than leaving the
 * action unhandled, makes the request fail cleanly and say why instead of erroring.
 *
 * @internal
 */
final class CardOnFileChargeCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return NmiCardOnFileChargerInterface::ACTION === $paymentRequest->getAction();
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new RefuseCardOnFileCharge($paymentRequest->getId());
    }
}
