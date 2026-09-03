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
        'environment' => ['element' => 'environment', 'message' => 'Please choose an environment.'],
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
     * @When I choose the :environment environment
     */
    public function iChooseTheEnvironment(string $environment): void
    {
        $this->createPage->chooseEnvironment($environment);
    }

    /**
     * @When I enable authorize-then-capture
     */
    public function iEnableAuthorizeThenCapture(): void
    {
        $this->createPage->enableAuthorizeThenCapture();
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
     * @Then /^I should be notified that the NMI (tokenization key|security key|environment) is required$/
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
