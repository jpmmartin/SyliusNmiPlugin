<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\RefuseRecurringCharge;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Answers the recurring charge's action with a refusal, always.
 *
 * The charger never announces its requests — it dispatches the charge itself — so the only requests
 * that reach this provider are ones created some other way: a client of the shop API naming the
 * action. Answering them with a refusal, rather than leaving the action unhandled, makes the request
 * fail cleanly and say why instead of erroring.
 *
 * @internal
 */
final class RecurringChargeCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return NmiRecurringChargerInterface::ACTION === $paymentRequest->getAction();
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new RefuseRecurringCharge($paymentRequest->getId());
    }
}
