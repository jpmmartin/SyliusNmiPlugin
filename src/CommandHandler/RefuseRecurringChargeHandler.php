<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\Command\RefuseRecurringCharge;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * Fails a request that named the recurring charge's action without coming through the charger.
 * Nothing is read, locked or sent: the request fails and says why.
 *
 * @internal
 */
final class RefuseRecurringChargeHandler
{
    public const NOT_HERE = 'jpm_martin_sylius_nmi.payment.recurring_charge_not_available_here';

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(RefuseRecurringCharge $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $paymentRequest->setResponseData(NmiChargeOutcome::refused(self::NOT_HERE)->toArray());

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }
}
