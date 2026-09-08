<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use Doctrine\ORM\Event\PreRemoveEventArgs;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Deleting a customer has to reach the gateway too, or their cards outlive them there.
 *
 * **The rows go by themselves**: the foreign key cascades, so nothing here removes them and no
 * listener on the card would ever fire — the database does it and Doctrine never hears about it.
 * What this listener is for is reading the identifiers *while they still exist*, because a moment
 * later there is nothing left to read them from.
 *
 * Two events rather than one, and the split is the point. `preRemove` is the last moment the cards
 * can be read; `postFlush` is the first moment it is true that they are gone. Dispatching from the
 * first would announce a deletion that a failing flush then undoes.
 *
 * **The bus it dispatches on is not the command bus, and that is not a preference.** `postFlush`
 * runs inside `EntityManager::flush()`, and `sylius.command_bus` carries the `doctrine_transaction`
 * middleware — so dispatching there flushes inside a flush, which Doctrine answers by trying to
 * persist the identity map a second time. Found by a test that failed with *"A new entity was found
 * through the relationship NmiStoredCard#customer"*, which is what a nested flush looks like from
 * the outside. `sylius.event_bus` carries no such middleware; the routing that sends this message
 * to a queue is per message class, so it is unaffected by which bus carried it.
 */
final class PurgeStoredCardsOnCustomerDeletionListener
{
    /** @var list<PurgeStoredCard> */
    private array $pending = [];

    public function __construct(
        private readonly NmiStoredCardRepositoryInterface $repository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function preRemove(PreRemoveEventArgs $event): void
    {
        $customer = $event->getObject();
        if (!$customer instanceof CustomerInterface) {
            return;
        }

        foreach ($this->repository->findByCustomer($customer) as $card) {
            $vaultId = $card->getVaultId();
            $paymentMethodCode = $card->getPaymentMethod()?->getCode();

            // A row missing either of them is one nothing could purge with. It cannot be produced
            // by this plugin — both columns are NOT NULL — so this is a guard rather than a case.
            if (null === $vaultId || null === $paymentMethodCode) {
                continue;
            }

            $this->pending[] = new PurgeStoredCard($vaultId, $paymentMethodCode);
        }
    }

    public function postFlush(): void
    {
        // Emptied before dispatching, not after: a dispatch that throws must not leave the same
        // messages queued for the next flush to send again.
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $message) {
            $this->bus->dispatch($message);
        }
    }
}
