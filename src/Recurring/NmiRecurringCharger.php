<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recurring;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\Command\ChargeRecurringCredential;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeRecurringCredentialHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The one door to charging a recurring credential.
 *
 * As the card-on-file charger does: a payment request is created for the record — the platform
 * encrypts its payload, which carries the credential's id — but it is **never announced**, because
 * announcing routes by action and the shop API lets a client pick the action. The charge is
 * dispatched here instead, on the payment-request bus, straight to its handler.
 *
 * @internal
 */
final class NmiRecurringCharger implements NmiRecurringChargerInterface
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly MessageBusInterface $paymentRequestBus,
    ) {
    }

    public function charge(PaymentInterface $payment, NmiRecurringCredentialInterface $credential, array $extra = []): NmiChargeOutcome
    {
        return $this->dispatch($payment, $credential, ['extra' => $extra]);
    }

    /**
     * The same charge, for the order screen's complete action, which is in the middle of completing
     * the payment itself: the payment is left for it.
     */
    public function chargeForTheOrderScreen(PaymentInterface $payment, NmiRecurringCredentialInterface $credential): NmiChargeOutcome
    {
        return $this->dispatch($payment, $credential, ['extra' => [], ChargeRecurringCredentialHandler::LEAVE_PAYMENT => true]);
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(PaymentInterface $payment, NmiRecurringCredentialInterface $credential, array $payload): NmiChargeOutcome
    {
        $method = $payment->getMethod();
        if (null === $method) {
            return NmiChargeOutcome::refused(ChargeRecurringCredentialHandler::OTHER_METHOD);
        }

        // A credential that was never stored, or whose row is gone with its customer, is one nobody
        // can charge any more — which is what "let go" means to a caller.
        $credentialId = $credential->getId();
        if (null === $credentialId) {
            return NmiChargeOutcome::refused(ChargeRecurringCredentialHandler::RELEASED);
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $method);
        $paymentRequest->setAction(self::ACTION);
        $paymentRequest->setPayload($payload + [ChargeRecurringCredentialHandler::CREDENTIAL => $credentialId]);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->paymentRequestBus->dispatch(new ChargeRecurringCredential($paymentRequest->getId()));

        // Read back from the request the handler finished. A request still open means the message
        // went somewhere other than straight to its handler — a store that routed the payment-request
        // bus to a queue — and then nothing is known yet, which is exactly what is said.
        return NmiChargeOutcome::fromArray($paymentRequest->getResponseData())
            ?? NmiChargeOutcome::unknown(ChargeRecurringCredentialHandler::UNKNOWN, 'The charge was not handled while the caller waited; the payment-request bus must be synchronous.');
    }
}
