<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\StoredCard\NmiDefaultCard;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;

/**
 * Turns "the shopper submitted the choose-this-one form" into the invariant being restored.
 *
 * It runs before the resource controller flushes, which is what lets the previous default be
 * cleared in the same transaction as the new one being set — there is no moment where the customer
 * has two defaults, or none.
 */
final readonly class NmiStoredCardDefaultListener
{
    public function __construct(
        private NmiDefaultCard $defaultCard,
    ) {
    }

    public function __invoke(ResourceControllerEvent $event): void
    {
        $card = $event->getSubject();
        if (!$card instanceof NmiStoredCardInterface) {
            return;
        }

        $this->defaultCard->promote($card);
    }
}
