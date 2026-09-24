<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recurring;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lets a recurring credential go through the queued purge that forgets a deleted customer's cards.
 *
 * **Queued before the row is marked**, as the card on file's release is and for the same reason: a
 * purge queued twice is harmless — the gateway no longer having the record counts as purged — while a
 * row marked let go whose purge never left would leave a card at the gateway that nothing here points
 * at any more. The event bus, as there: the purge handler lives on it, and which bus carries the
 * message does not change where it goes.
 *
 * @internal
 */
final class NmiRecurringCredentialReleaser implements NmiRecurringCredentialReleaserInterface
{
    public function __construct(
        private readonly MessageBusInterface $eventBus,
        private readonly ObjectManager $manager,
    ) {
    }

    public function release(NmiRecurringCredentialInterface $credential): void
    {
        if ($credential->isReleased()) {
            return;
        }

        $vaultId = $credential->getVaultId();
        $methodCode = $credential->getPaymentMethod()?->getCode();

        // Both columns are required, so this is a guard rather than a case: without either there is
        // nothing at the gateway this row could point at, and the credential is let go all the same.
        if (null !== $vaultId && '' !== $vaultId && null !== $methodCode) {
            $this->eventBus->dispatch(new PurgeStoredCard($vaultId, $methodCode));
        }

        $credential->setReleasedAt(new \DateTimeImmutable());
        $this->manager->flush();
    }
}
