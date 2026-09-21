<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Sylius\Behat\NotificationType;
use Sylius\Behat\Service\NotificationCheckerInterface;

/**
 * What the operator is told when the card on file is not charged. Every other step a held-order
 * scenario needs — the order page, marking it paid, the payment's state, its payment requests — is
 * Sylius's own.
 */
final class CompletingNmiHeldOrdersContext implements Context
{
    public function __construct(
        private readonly NotificationCheckerInterface $notificationChecker,
    ) {
    }

    /**
     * @Then I should be notified that the card on file was declined with :reason
     */
    public function iShouldBeNotifiedThatTheCardOnFileWasDeclinedWith(string $reason): void
    {
        $this->notificationChecker->checkNotification($reason, NotificationType::failure());
    }
}
