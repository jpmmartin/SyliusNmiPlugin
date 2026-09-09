<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Doctrine\DBAL\Connection;
use Sylius\Behat\Page\Admin\Crud\CreatePageInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod\IndexPage;
use Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod\NmiGatewayConfigurationPageInterface;
use Webmozart\Assert\Assert;

final class ManagingNmiPaymentMethodsContext implements Context
{
    /** @var array<string, array{element: string, message: string}> */
    private const REQUIRED_FIELDS = [
        'tokenization key' => ['element' => 'tokenization_key', 'message' => 'Please enter the tokenization key.'],
        'security key' => ['element' => 'security_key', 'message' => 'Please enter the security key.'],
        'gateway host' => ['element' => 'api_base_url', 'message' => 'Please enter the gateway host.'],
    ];

    public function __construct(
        private readonly CreatePageInterface&NmiGatewayConfigurationPageInterface $createPage,
        private readonly NmiGatewayConfigurationPageInterface $updatePage,
        private readonly IndexPage $indexPage,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @When I set its tokenization key as :key
     */
    public function iSetItsTokenizationKeyAs(string $key): void
    {
        $this->createPage->setTokenizationKey($key);
    }

    /**
     * @When I set its security key as :key
     */
    public function iSetItsSecurityKeyAs(string $key): void
    {
        $this->createPage->setSecurityKey($key);
    }

    /**
     * @When I set the gateway host to :host
     */
    public function iSetTheGatewayHostTo(string $host): void
    {
        $this->createPage->setGatewayHost($host);
    }

    /**
     * @When I enable authorize-then-capture
     */
    public function iEnableAuthorizeThenCapture(): void
    {
        $this->createPage->enableAuthorizeThenCapture();
    }

    /**
     * @When I let shoppers save their card
     */
    public function iLetShoppersSaveTheirCard(): void
    {
        $this->createPage->enableCardSaving();
    }

    /**
     * @When I turn off authenticating saved cards
     */
    public function iTurnOffAuthenticatingSavedCards(): void
    {
        $this->createPage->disableStoredCardAuthentication();
    }

    /**
     * @Then NMI should be available as a gateway factory
     */
    public function nmiShouldBeAvailableAsAGatewayFactory(): void
    {
        Assert::inArray('NMI', $this->indexPage->getAvailableGatewayFactories());
    }

    /**
     * Turnip placeholders stop at whitespace, so multi-word field names need a regex.
     *
     * @Then /^I should be notified that the NMI (tokenization key|security key|gateway host) is required$/
     */
    public function iShouldBeNotifiedThatTheFieldIsRequired(string $field): void
    {
        Assert::keyExists(self::REQUIRED_FIELDS, $field, sprintf('Unknown NMI gateway field "%s".', $field));

        Assert::same(
            $this->createPage->getValidationMessage(self::REQUIRED_FIELDS[$field]['element']),
            self::REQUIRED_FIELDS[$field]['message'],
        );
    }

    /**
     * @Then this payment method should charge cards immediately
     */
    public function thisPaymentMethodShouldChargeCardsImmediately(): void
    {
        Assert::false($this->updatePage->isAuthorizeThenCaptureEnabled());
    }

    /**
     * @Then this payment method should authorize first and capture later
     */
    public function thisPaymentMethodShouldAuthorizeFirstAndCaptureLater(): void
    {
        Assert::true($this->updatePage->isAuthorizeThenCaptureEnabled());
    }

    /**
     * @Then this payment method should let shoppers save their card
     */
    public function thisPaymentMethodShouldLetShoppersSaveTheirCard(): void
    {
        Assert::true($this->updatePage->isCardSavingEnabled());
    }

    /**
     * @Then this payment method should not let shoppers save their card
     */
    public function thisPaymentMethodShouldNotLetShoppersSaveTheirCard(): void
    {
        Assert::false($this->updatePage->isCardSavingEnabled());
    }

    /**
     * @Then this payment method should authenticate saved cards
     */
    public function thisPaymentMethodShouldAuthenticateSavedCards(): void
    {
        Assert::true($this->updatePage->isStoredCardAuthenticationEnabled());
    }

    /**
     * @Then this payment method should not authenticate saved cards
     */
    public function thisPaymentMethodShouldNotAuthenticateSavedCards(): void
    {
        Assert::false($this->updatePage->isStoredCardAuthenticationEnabled());
    }

    /**
     * The setting is offered already on, because that is what the code reads an unanswered
     * question as — and a form that showed it off would write the answer nobody gave.
     *
     * @Then authenticating saved cards should be offered already on
     */
    public function authenticatingSavedCardsShouldBeOfferedAlreadyOn(): void
    {
        Assert::true($this->createPage->isStoredCardAuthenticationEnabled());
    }

    /**
     * @Then saving cards should be offered off
     */
    public function savingCardsShouldBeOfferedOff(): void
    {
        Assert::false($this->createPage->isCardSavingEnabled());
    }

    /**
     * The scenario this serves is about words: an operator who has never read the specification
     * has to be able to make the choice from the form alone. So the phrase is named in the feature
     * file and checked here, rather than the test settling for "some help text exists".
     *
     * A phrase in the feature file must not begin with "the": Sylius transforms a quoted argument
     * that does into a shared-storage lookup, and the step fails hunting for a key nobody stored.
     *
     * @Then /^the saved-card authentication setting should warn that "([^"]+)"$/
     */
    public function theSavedCardAuthenticationSettingShouldWarnThat(string $phrase): void
    {
        Assert::contains($this->createPage->getStoredCardAuthenticationHelp(), $phrase);
    }

    /**
     * @Then the security key :key of the :paymentMethod payment method should not be readable in the database
     */
    public function theSecurityKeyShouldNotBeReadableInTheDatabase(string $key, PaymentMethodInterface $paymentMethod): void
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        Assert::notNull($gatewayConfig);

        // Straight from the row, bypassing the ORM listener that decrypts on load.
        $storedConfig = $this->connection->fetchOne(
            'SELECT config FROM sylius_gateway_config WHERE id = :id',
            ['id' => $gatewayConfig->getId()],
        );
        Assert::string($storedConfig);
        Assert::notContains($storedConfig, $key, 'The security key is stored in plain text.');

        /** @var array<string, string> $decoded */
        $decoded = json_decode($storedConfig, true, 512, \JSON_THROW_ON_ERROR);
        Assert::keyExists($decoded, 'security_key');
        Assert::endsWith($decoded['security_key'], EncrypterInterface::ENCRYPTION_SUFFIX);
    }
}
