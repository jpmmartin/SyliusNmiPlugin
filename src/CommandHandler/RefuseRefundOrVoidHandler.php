<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\RefuseRefundOrVoid;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * Fails a refund or void request that this plugin did not send itself. Nothing is read or sent: the
 * request fails and says why, and the payment is left as it is.
 *
 * @internal
 */
final class RefuseRefundOrVoidHandler
{
    public const NOT_HERE = 'jpm_martin_sylius_nmi.payment.refund_or_void_not_available_here';

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(RefuseRefundOrVoid $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $paymentRequest->setResponseData(['message_key' => self::NOT_HERE, 'detail' => null]);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }
}
