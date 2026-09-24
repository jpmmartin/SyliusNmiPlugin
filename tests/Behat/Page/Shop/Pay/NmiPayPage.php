<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Shop\Pay;

use Sylius\Behat\Page\SyliusPage;

/**
 * The page a shopper lands on after confirming an order paid by card: the platform's own pay route,
 * rendered by this plugin. Read only from the server's markup — the card fields are the gateway's
 * frames and need a browser, but what the page offers around them does not.
 */
final class NmiPayPage extends SyliusPage
{
    public function getRouteName(): string
    {
        return 'sylius_shop_payment_request_pay';
    }

    public function getRecurringCommitment(): ?string
    {
        return $this->getDocument()->find('css', '[data-test-nmi-recurring-commitment]')?->getText();
    }

    public function offersToSaveTheCard(): bool
    {
        return null !== $this->getDocument()->find('css', '[data-test-nmi-store-card]');
    }

    public function offersSavedCards(): bool
    {
        return null !== $this->getDocument()->find('css', '[data-test-nmi-stored-cards]');
    }

    public function getPayButtonLabel(): string
    {
        $button = $this->getDocument()->find('css', '[data-test-nmi-card-pay]');
        if (null === $button) {
            throw new \RuntimeException('The pay page has no pay button.');
        }

        return trim($button->getText());
    }
}
