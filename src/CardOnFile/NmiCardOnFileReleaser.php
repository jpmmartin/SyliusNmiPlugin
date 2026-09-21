<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CardOnFile;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lets go of the card a payment holds, once the payment has been charged or cancelled.
 *
 * The record at the gateway is forgotten by the same queued purge that forgets a deleted customer's
 * cards, which retries until the gateway has done it. **Queued before the row is marked**, and in that
 * order on purpose: a purge queued twice is harmless — the gateway no longer having the record counts
 * as purged — while a row marked released whose purge never left would leave a card at the gateway
 * that nothing here points at any more.
 *
 * Called only after the charge or the cancellation has been committed, so a charge that rolled back
 * never releases the card it did not take.
 *
 * @internal
 */
final class NmiCardOnFileReleaser
{
    public function __construct(
        private readonly NmiCardOnFileRepositoryInterface $cardsOnFile,
        private readonly MessageBusInterface $eventBus,
        private readonly ObjectManager $manager,
    ) {
    }

    public function release(PaymentInterface $payment): void
    {
        $card = $this->cardsOnFile->findHeldBy($payment);
        $vaultId = $card?->getVaultId();
        $methodCode = $card?->getPaymentMethod()?->getCode();

        if (null === $card || null === $vaultId || '' === $vaultId || null === $methodCode) {
            return;
        }

        $this->eventBus->dispatch(new PurgeStoredCard($vaultId, $methodCode));

        $card->setReleasedAt(new \DateTimeImmutable());
        $this->manager->flush();
    }
}
