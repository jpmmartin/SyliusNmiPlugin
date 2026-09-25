<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lets go of the card on file of every payment a flush saves as cancelled, whatever cancelled it.
 *
 * **The flush, not the order screen.** Sylius cancels an order's payments through its state machine,
 * and so do the scheduled cancellation of unpaid orders and a store's own code; none of them fires
 * the order screen's events. Every one of them ends in a flush that saves the payment as cancelled,
 * and nothing that is not saved does — so that is where the card is let go.
 *
 * **Two events, and the split is the point**, as it is for a deleted customer's cards. `onFlush`
 * marks the card released inside the flush's own transaction, so the release is saved exactly when
 * the cancellation is. `postFlush` queues the removal from the gateway once that transaction has
 * committed, on the event bus: its handler writes nothing to the database, so the queue can even be
 * synchronous without a flush running inside this one.
 *
 * **Tagged above the card on file's encryption listener.** That listener encrypts only the updates
 * already scheduled when it runs; the card scheduled here must be one of them, or its references
 * would be written in plain text. The vault reference is read before it is encrypted, for the same
 * reason.
 *
 * A recurring credential is left alone: cancelling the payment that opened one never lets it go.
 *
 * @internal
 */
final class ReleaseCardsOfCancelledPaymentsListener
{
    /** @var list<PurgeStoredCard> */
    private array $pending = [];

    public function __construct(
        private readonly NmiCardOnFileRepositoryInterface $cardsOnFile,
        private readonly MessageBusInterface $eventBus,
    ) {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        // Replaced, not appended: a flush that failed after this ran must leave nothing for the next.
        $this->pending = [];

        $manager = $event->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $payment) {
            if (!$payment instanceof PaymentInterface) {
                continue;
            }

            $state = $unitOfWork->getEntityChangeSet($payment)['state'] ?? null;
            if (!is_array($state) || PaymentInterface::STATE_CANCELLED !== $state[1] || PaymentInterface::STATE_CANCELLED === $state[0]) {
                continue;
            }

            $card = $this->cardsOnFile->findHeldBy($payment);
            if (null === $card) {
                continue;
            }

            $vaultId = $card->getVaultId();
            $methodCode = $card->getPaymentMethod()?->getCode();

            $card->setReleasedAt(new \DateTimeImmutable());
            $unitOfWork->recomputeSingleEntityChangeSet($manager->getClassMetadata($card::class), $card);

            // Both columns are required, so this is a guard rather than a case.
            if (null !== $vaultId && '' !== $vaultId && null !== $methodCode) {
                $this->pending[] = new PurgeStoredCard($vaultId, $methodCode);
            }
        }
    }

    public function postFlush(): void
    {
        // Emptied before dispatching: a dispatch that throws must not queue the same removals again.
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $purge) {
            $this->eventBus->dispatch($purge);
        }
    }
}
