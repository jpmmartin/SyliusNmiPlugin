<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Shop\Pay\NmiPayPage;
use Webmozart\Assert\Assert;

/**
 * What the pay page offers the shopper. Reaching it — the cart, the checkout, confirming the order —
 * is Sylius's own.
 */
final class PayingWithNmiContext implements Context
{
    private const DEFAULT_COMMITMENT = 'This card will be kept so that the store can charge it again for renewals of this purchase';

    public function __construct(
        private readonly NmiPayPage $payPage,
    ) {
    }

    /**
     * @Then I should be told my card will be kept for renewals
     */
    public function iShouldBeToldMyCardWillBeKeptForRenewals(): void
    {
        Assert::contains((string) $this->payPage->getRecurringCommitment(), self::DEFAULT_COMMITMENT);
    }

    /**
     * @Then I should not be told anything about renewals
     */
    public function iShouldNotBeToldAnythingAboutRenewals(): void
    {
        Assert::null($this->payPage->getRecurringCommitment());
    }

    /**
     * @Then the pay button should say :label
     */
    public function thePayButtonShouldSay(string $label): void
    {
        Assert::same($this->payPage->getPayButtonLabel(), $label);
    }

    /**
     * @Then I should be offered to save my card
     */
    public function iShouldBeOfferedToSaveMyCard(): void
    {
        Assert::true($this->payPage->offersToSaveTheCard());
    }

    /**
     * @Then I should not be offered to save my card
     */
    public function iShouldNotBeOfferedToSaveMyCard(): void
    {
        Assert::false($this->payPage->offersToSaveTheCard());
    }

    /**
     * @Then I should be offered my saved cards
     */
    public function iShouldBeOfferedMySavedCards(): void
    {
        Assert::true($this->payPage->offersSavedCards());
    }

    /**
     * @Then I should not be offered my saved cards
     */
    public function iShouldNotBeOfferedMySavedCards(): void
    {
        Assert::false($this->payPage->offersSavedCards());
    }
}
