<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * The store a saved-cards scenario needs: an NMI payment method that stores cards, and cards on it.
 *
 * The cards are written straight to the database rather than earned through the storefront,
 * because earning one means tokenising a card in a browser against NMI — which no scenario in
 * continuous integration can do. What that costs is stated where it matters: the flows that put a
 * card on file are covered by the functional suite and by the sandbox walkthrough, not here.
 */
final class NmiStoredCardContext implements Context
{
    /**
     * @param FactoryInterface<NmiStoredCardInterface> $storedCardFactory
     * @param RepositoryInterface<CustomerInterface> $customerRepository
     */
    public function __construct(
        private readonly SharedStorageInterface $sharedStorage,
        private readonly FactoryInterface $storedCardFactory,
        private readonly FactoryInterface $paymentMethodFactory,
        private readonly FactoryInterface $gatewayConfigFactory,
        private readonly RepositoryInterface $customerRepository,
        private readonly ObjectManager $manager,
    ) {
    }

    /**
     * @Given the store has an NMI payment method :name with a code :code that lets shoppers save their card
     */
    public function theStoreHasAnNmiPaymentMethodThatLetsShoppersSaveTheirCard(string $name, string $code): void
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $this->gatewayConfigFactory->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        // Without this Sylius treats the method as a Payum gateway and stores its credentials in
        // the clear, which is not the configuration a store actually has.
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-public-0123',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-private-4567',
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            NmiGatewayFactory::CONFIG_STORE_CARDS => true,
        ]);

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->paymentMethodFactory->createNew();
        $paymentMethod->setCode($code);
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName($name);
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);

        $channel = $this->sharedStorage->has('channel') ? $this->sharedStorage->get('channel') : null;
        if (null !== $channel) {
            $paymentMethod->addChannel($channel);
        }

        $this->manager->persist($gatewayConfig);
        $this->manager->persist($paymentMethod);
        $this->manager->flush();

        $this->sharedStorage->set('nmi_payment_method', $paymentMethod);
    }

    /**
     * @Given /^I have a saved "([^"]+)" card ending "(\d{4})"$/
     */
    public function iHaveASavedCardEnding(string $brand, string $lastFour): void
    {
        $this->saveCard($brand, $lastFour, false, 10, 2035);
    }

    /**
     * @Given /^I have a saved "([^"]+)" card ending "(\d{4})" that is my default$/
     */
    public function iHaveASavedCardEndingThatIsMyDefault(string $brand, string $lastFour): void
    {
        $this->saveCard($brand, $lastFour, true, 10, 2035);
    }

    /**
     * @Given /^I have a saved "([^"]+)" card ending "(\d{4})" that expired in (\d{2})\/(\d{4})$/
     */
    public function iHaveASavedCardEndingThatExpired(string $brand, string $lastFour, string $month, string $year): void
    {
        $this->saveCard($brand, $lastFour, false, (int) $month, (int) $year);
    }

    private function saveCard(string $brand, string $lastFour, bool $default, int $expiryMonth, int $expiryYear): void
    {
        /** @var NmiStoredCardInterface $card */
        $card = $this->storedCardFactory->createNew();
        $card->setCustomer($this->currentCustomer());
        $card->setPaymentMethod($this->sharedStorage->get('nmi_payment_method'));
        // Unique per card, and never asserted on in a scenario: the shopper is shown the last four
        // digits, and the gateway's own reference is not theirs to see.
        $card->setVaultId('vault-' . $lastFour);
        $card->setBillingId('billing-' . $lastFour);
        $card->setBrand($brand);
        $card->setLastFour($lastFour);
        $card->setExpiryMonth($expiryMonth);
        $card->setExpiryYear($expiryYear);
        $card->setDefault($default);

        $this->manager->persist($card);
        $this->manager->flush();
    }

    /**
     * The customer the scenario signed in as.
     *
     * Read back from the repository rather than from shared storage: the signing-in step stores a
     * shop *user*, and an entity held across a request is no longer the managed one.
     */
    private function currentCustomer(): CustomerInterface
    {
        $email = $this->sharedStorage->get('user')->getEmail();

        return $this->customerRepository->findOneBy(['email' => $email])
            ?? throw new \RuntimeException(sprintf('No customer with the email "%s".', $email));
    }
}
