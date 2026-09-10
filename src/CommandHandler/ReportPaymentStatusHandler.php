<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\ReportPaymentStatus;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * Closes a status request without asking the gateway anything.
 *
 * The charge already decided the payment's state and wrote its own record; re-reading the
 * transaction would return what the store already knows. It also could not rescue the one case
 * where a question would help — a charge whose response was lost never yielded an identifier to
 * ask about. This is what the platform's own offline gateway does with the same action.
 *
 * @internal
 */
final class ReportPaymentStatusHandler
{
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(ReportPaymentStatus $command): void
    {
        $this->stateMachine->apply(
            $this->paymentRequestProvider->provide($command),
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }
}
