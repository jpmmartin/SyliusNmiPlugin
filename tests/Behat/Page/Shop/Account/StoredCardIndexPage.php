<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Shop\Account;

use Behat\Mink\Element\NodeElement;
use Sylius\Behat\Page\SyliusPage;

/**
 * The shopper's saved-cards page.
 *
 * Every card is found by its row identifier rather than by position, because the page orders the
 * default first and a test that counted from the top would pass or fail on the ordering rather
 * than on what it means to.
 */
final class StoredCardIndexPage extends SyliusPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_nmi_shop_account_stored_card_index';
    }

    public function countCards(): int
    {
        return count($this->getDocument()->findAll('css', '[data-test-nmi-stored-card]'));
    }

    public function hasCardEnding(string $lastFour): bool
    {
        return null !== $this->findCardEnding($lastFour);
    }

    public function hasEmptyMessage(): bool
    {
        return str_contains($this->getDocument()->getText(), 'You have no saved cards yet');
    }

    public function isDefault(string $lastFour): bool
    {
        return null !== $this->card($lastFour)->find('css', '[data-test-nmi-stored-card-default]');
    }

    public function isExpired(string $lastFour): bool
    {
        return null !== $this->card($lastFour)->find('css', '[data-test-nmi-stored-card-expired]');
    }

    /** An expired card cannot become the default, so its button is not rendered at all. */
    public function canBeMadeDefault(string $lastFour): bool
    {
        return null !== $this->card($lastFour)->find('css', '[data-test-nmi-stored-card-make-default]');
    }

    public function makeDefault(string $lastFour): void
    {
        $button = $this->card($lastFour)->find('css', '[data-test-nmi-stored-card-make-default]');

        if (null === $button) {
            throw new \RuntimeException(sprintf('The card ending %s cannot be made the default from this page.', $lastFour));
        }

        $button->press();
    }

    /**
     * Submits the platform's own delete form rather than requesting the route, so the token the
     * resource controller checks is the one the page actually rendered.
     */
    public function delete(string $lastFour): void
    {
        $button = $this->card($lastFour)->find('css', '[data-test-button="delete"]');

        if (null === $button) {
            throw new \RuntimeException(sprintf('No delete button for the card ending %s.', $lastFour));
        }

        $button->press();
    }

    /** @return array<string, string> */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'cards' => '[data-test-nmi-stored-card]',
        ]);
    }

    private function card(string $lastFour): NodeElement
    {
        return $this->findCardEnding($lastFour)
            ?? throw new \RuntimeException(sprintf('No saved card ending %s on this page.', $lastFour));
    }

    private function findCardEnding(string $lastFour): ?NodeElement
    {
        foreach ($this->getDocument()->findAll('css', '[data-test-nmi-stored-card]') as $card) {
            if (str_contains($card->getText(), '•••• ' . $lastFour)) {
                return $card;
            }
        }

        return null;
    }
}
