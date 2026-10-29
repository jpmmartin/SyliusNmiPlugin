<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\RefuseRefundOrVoid;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Answers an announced refund request with a refusal, always.
 *
 * This plugin sends its own refunds to the bus itself rather than announcing them, so the only refund
 * requests announced are ones created some other way: a client of the shop API naming the action,
 * which Sylius before 2.2.9 — or a store that widens `sylius_api.shop_payment_request.allowed_actions`
 * — lets it do. Moving money for those would give a shopper their payment back with the order still
 * paid. Answering with a refusal, rather than leaving the action unhandled, makes the request fail
 * cleanly and say why.
 *
 * @internal
 */
final class RefundCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::ACTION_REFUND === $paymentRequest->getAction();
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new RefuseRefundOrVoid($paymentRequest->getId());
    }
}
