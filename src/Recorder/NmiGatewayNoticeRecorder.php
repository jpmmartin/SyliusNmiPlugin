<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recorder;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNoticeInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiGatewayNoticeRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * The one place a notice is written, so the "same thing twice is one notice" rule has one home.
 *
 * **Why a lookup here and not a constraint violation, when the event ledger does the opposite.**
 * The ledger guards a live race: two deliveries of the same event arriving together, which the
 * gateway's retries make ordinary. This guards a much rarer thing — a redelivery after the event
 * record has been pruned, days later — and by then there is no concurrent twin to race with. The
 * unique index is still there, and is what would catch it if that reasoning is ever wrong.
 *
 * @internal
 */
final class NmiGatewayNoticeRecorder implements NmiGatewayNoticeRecorderInterface
{
    /** @param FactoryInterface<NmiGatewayNoticeInterface> $noticeFactory */
    public function __construct(
        private readonly FactoryInterface $noticeFactory,
        private readonly NmiGatewayNoticeRepositoryInterface $noticeRepository,
        private readonly EntityManagerInterface $manager,
    ) {
    }

    public function record(
        string $type,
        ?string $reference,
        string $paymentMethodCode,
        ?PaymentInterface $payment = null,
        ?int $amount = null,
        ?string $currencyCode = null,
        ?string $reason = null,
    ): bool {
        if (null !== $reference && null !== $this->noticeRepository->findOneBy(['type' => $type, 'reference' => $reference])) {
            return false;
        }

        $notice = $this->noticeFactory->createNew();
        $notice->setType($type);
        $notice->setReference($reference);
        $notice->setPaymentMethodCode($paymentMethodCode);
        $notice->setPayment($payment);
        $notice->setAmount($amount);
        $notice->setCurrencyCode($currencyCode);
        $notice->setReason($reason);
        $notice->setOccurredAt(new \DateTimeImmutable());

        $this->manager->persist($notice);
        // Flushed here rather than left to a caller: the webhook endpoint is not inside the
        // payment-request command bus, so nothing else in this request is going to commit for it.
        $this->manager->flush();

        return true;
    }
}
