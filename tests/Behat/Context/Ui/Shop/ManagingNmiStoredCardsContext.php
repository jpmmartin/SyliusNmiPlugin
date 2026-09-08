<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Shop\Account\StoredCardIndexPage;
use Webmozart\Assert\Assert;

/**
 * The saved-cards page in the shopper's account, driven the way a shopper drives it.
 *
 * The functional suite already asks the same routes the same questions; what this adds is that the
 * answers are reached through the rendered page — a button that stopped being rendered, or a form
 * whose token moved, fails here and passes there.
 */
final class ManagingNmiStoredCardsContext implements Context
{
    public function __construct(private readonly StoredCardIndexPage $indexPage)
    {
    }

    /**
     * @When I browse my saved cards
     */
    public function iBrowseMySavedCards(): void
    {
        $this->indexPage->open();
    }

    /**
     * @When /^I make the card ending "(\d{4})" my default$/
     */
    public function iMakeTheCardMyDefault(string $lastFour): void
    {
        $this->indexPage->makeDefault($lastFour);
    }

    /**
     * @When /^I delete the card ending "(\d{4})"$/
     */
    public function iDeleteTheCard(string $lastFour): void
    {
        $this->indexPage->delete($lastFour);
    }

    /**
     * @Then I should see :count saved cards
     * @Then I should see :count saved card
     */
    public function iShouldSeeSavedCards(int $count): void
    {
        Assert::same($this->indexPage->countCards(), $count);
    }

    /**
     * @Then /^I should see a saved card ending "(\d{4})"$/
     */
    public function iShouldSeeASavedCardEnding(string $lastFour): void
    {
        Assert::true($this->indexPage->hasCardEnding($lastFour));
    }

    /**
     * @Then /^I should not see a saved card ending "(\d{4})"$/
     */
    public function iShouldNotSeeASavedCardEnding(string $lastFour): void
    {
        Assert::false($this->indexPage->hasCardEnding($lastFour));
    }

    /**
     * @Then I should be told I have no saved cards
     */
    public function iShouldBeToldIHaveNoSavedCards(): void
    {
        Assert::true($this->indexPage->hasEmptyMessage());
        Assert::same($this->indexPage->countCards(), 0);
    }

    /**
     * @Then /^the card ending "(\d{4})" should be my default$/
     */
    public function theCardShouldBeMyDefault(string $lastFour): void
    {
        Assert::true($this->indexPage->isDefault($lastFour));
    }

    /**
     * @Then /^the card ending "(\d{4})" should not be my default$/
     */
    public function theCardShouldNotBeMyDefault(string $lastFour): void
    {
        Assert::false($this->indexPage->isDefault($lastFour));
    }

    /**
     * @Then /^the card ending "(\d{4})" should be shown as expired$/
     */
    public function theCardShouldBeShownAsExpired(string $lastFour): void
    {
        Assert::true($this->indexPage->isExpired($lastFour));
    }

    /**
     * An expired card is shown, so the shopper knows why it is no use, and cannot be chosen — the
     * button is not rendered rather than rendered and refused.
     *
     * @Then /^I should not be able to make the card ending "(\d{4})" my default$/
     */
    public function iShouldNotBeAbleToMakeTheCardMyDefault(string $lastFour): void
    {
        Assert::false($this->indexPage->canBeMadeDefault($lastFour));
    }
}
