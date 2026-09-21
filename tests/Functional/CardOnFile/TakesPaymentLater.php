<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * What a store looks like once a payment method takes payment later, and what a payment looks like
 * once its card is on file — built here rather than found, because continuous integration starts
 * from an empty database.
 */
trait TakesPaymentLater
{
    abstract protected function paymentRequestManager(): EntityManagerInterface;

    /** Turns the setting on for a method the payment request fixture already built. */
    private function takePaymentLaterOn(PaymentMethodInterface $method, bool $on = true): void
    {
        $gatewayConfig = $method->getGatewayConfig();
        self::assertNotNull($gatewayConfig);

        $gatewayConfig->setConfig([NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER => $on] + $gatewayConfig->getConfig());
        $this->paymentRequestManager()->flush();
    }

    /**
     * A card on file for a payment, as the deferred checkout leaves it: the payment waiting in
     * processing, the card holding the vault record and the verification a later charge cites.
     */
    private function aCardOnFileFor(
        PaymentInterface $payment,
        ?string $initialTransactionId = '12584742193',
        string $status = NmiCardOnFileInterface::STATUS_ACTIVE,
        int $expiryYear = 2031,
    ): NmiCardOnFileInterface {
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiCardOnFileInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_card_on_file')->createNew();
        $card->setPayment($payment);
        $card->setPaymentMethod($method);
        $card->setVaultId('1256465022');
        $card->setInitialTransactionId($initialTransactionId);
        $card->setBrand('Visa');
        $card->setLastFour('1111');
        $card->setExpiryMonth(10);
        $card->setExpiryYear($expiryYear);
        $card->setStatus($status);
        $this->paymentRequestManager()->persist($card);

        $payment->setState(PaymentInterface::STATE_PROCESSING);
        $this->paymentRequestManager()->flush();

        return $card;
    }

    private function heldCardOf(PaymentInterface $payment): ?NmiCardOnFileInterface
    {
        /** @var \JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_card_on_file');

        return $repository->findHeldBy($payment);
    }
}
