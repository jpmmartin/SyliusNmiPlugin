<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CardOnFile;

use JpmMartin\SyliusNmiPlugin\Command\ChargeCardOnFile;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeCardOnFileHandler;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The one door to charging a card on file.
 *
 * A payment request is created for the record — the platform encrypts what it carries, and it is
 * what an operator finds on the order afterwards — but it is **never announced**. Announcing it
 * would route by action, and the shop API lets a client pick the action. The charge is dispatched
 * here instead, on the payment-request bus, straight to its handler.
 *
 * @internal
 */
final class NmiCardOnFileCharger implements NmiCardOnFileChargerInterface
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly MessageBusInterface $paymentRequestBus,
        private readonly NmiCardOnFileReleaser $releaser,
    ) {
    }

    public function charge(PaymentInterface $payment, array $extra = []): NmiChargeOutcome
    {
        $outcome = $this->dispatch($payment, ['extra' => $extra]);

        // After the bus has returned, which is after the charge was committed: a charge that rolled
        // back never gets here approved, and never releases the card it did not take.
        if ($outcome->isApproved()) {
            $this->releaser->release($payment);
        }

        return $outcome;
    }

    /**
     * The same charge, for the order screen's complete action, which is in the middle of completing
     * the payment itself: the payment is left for it, and so is the release, which follows once the
     * completion has been committed.
     */
    public function chargeForTheOrderScreen(PaymentInterface $payment): NmiChargeOutcome
    {
        return $this->dispatch($payment, ['extra' => [], ChargeCardOnFileHandler::LEAVE_PAYMENT => true]);
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(PaymentInterface $payment, array $payload): NmiChargeOutcome
    {
        $method = $payment->getMethod();
        if (null === $method) {
            return NmiChargeOutcome::refused(ChargeCardOnFileHandler::NO_CARD_ON_FILE);
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $method);
        $paymentRequest->setAction(self::ACTION);
        $paymentRequest->setPayload($payload);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->paymentRequestBus->dispatch(new ChargeCardOnFile($paymentRequest->getId()));

        // Read back from the request the handler finished. A request still open means the message
        // went somewhere other than straight to its handler — a store that routed the payment-request
        // bus to a queue — and then nothing is known yet, which is exactly what is said.
        return NmiChargeOutcome::fromArray($paymentRequest->getResponseData())
            ?? NmiChargeOutcome::unknown(ChargeCardOnFileHandler::UNKNOWN, 'The charge was not handled while the caller waited; the payment-request bus must be synchronous.');
    }
}
