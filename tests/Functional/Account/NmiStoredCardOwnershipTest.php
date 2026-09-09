<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Account;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CreatesAShopChannel;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * One customer reaching for another's card.
 *
 * This is written as an attack rather than as a reading of the routing file, because the routing
 * file is exactly what would look right while being wrong. Every request below is one a signed-in
 * shopper can make with nothing but somebody else's row id.
 *
 * The answer has to be 404 and not a redirect: a redirect to the login page would mean the
 * boundary is the session rather than ownership, and a redirect to the listing would mean the
 * request was accepted and quietly did nothing — which is indistinguishable from success.
 */
final class NmiStoredCardOwnershipTest extends WebTestCase
{
    use CreatesAShopChannel;

    private const PATH = '/en_US/account/saved-cards';

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /** Somebody else's card is not on my page, whatever it says on theirs. */
    public function testAnotherCustomersCardIsNotInMyListing(): void
    {
        $victim = $this->aCustomerWithACard('9999');
        $this->signIn($this->aCustomer());

        $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('9999', (string) $this->client->getResponse()->getContent());
        self::assertNotNull($victim);
    }

    public function testDeletingAnotherCustomersCardIs404(): void
    {
        $victimCard = $this->aCustomerWithACard('9999');
        $this->signIn($this->aCustomer());

        // The page has to be fetched first so the attacker holds a session and a token of their
        // own — otherwise this would prove only that an anonymous request fails.
        $this->client->request('GET', self::PATH);
        // Any token at all: the ownership query runs before the token is checked, so what an
        // attacker sends here cannot change the answer. That ordering is asserted on its own below.
        $this->client->request('DELETE', sprintf('%s/%d', self::PATH, $victimCard->getId()), [
            '_csrf_token' => 'whatever-an-attacker-would-send',
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Not a redirect, and not a silent success.');
        self::assertNotNull($this->find($victimCard), 'The card must still be there.');
        self::assertSame([], $this->gateway->deletedVaultIds, 'And the gateway must never have been asked.');
    }

    public function testMakingAnotherCustomersCardMyDefaultIs404(): void
    {
        $victimCard = $this->aCustomerWithACard('9999');
        $this->signIn($this->aCustomer());

        $this->client->request('GET', self::PATH);
        $this->client->request('PATCH', sprintf('%s/%d/default', self::PATH, $victimCard->getId()), [
            'jpm_martin_sylius_nmi_stored_card_default' => ['_token' => 'whatever-an-attacker-would-send'],
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->find($victimCard)?->isDefault(), "The victim's own default must be untouched.");
    }

    /**
     * The ownership query runs before the token is checked, so a foreign id is refused even by a
     * request carrying no token at all — the boundary does not depend on CSRF holding.
     */
    public function testAForeignIdIs404EvenWithoutAToken(): void
    {
        $victimCard = $this->aCustomerWithACard('9999');
        $this->signIn($this->aCustomer());

        $this->client->request('GET', self::PATH);
        $this->client->request('DELETE', sprintf('%s/%d', self::PATH, $victimCard->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->find($victimCard));
    }

    private function find(NmiStoredCardInterface $card): ?NmiStoredCardInterface
    {
        /** @var NmiStoredCardInterface|null $found */
        $found = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card')->find($card->getId());

        return $found;
    }

    private function aCustomerWithACard(string $lastFour): NmiStoredCardInterface
    {
        $customer = $this->aCustomer();

        /** @var NmiStoredCardInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($customer);
        $card->setPaymentMethod($this->aPaymentMethod());
        $card->setVaultId('vault-' . $lastFour);
        $card->setBillingId('billing-' . $lastFour);
        $card->setBrand('visa');
        $card->setLastFour($lastFour);
        $card->setExpiryMonth(10);
        $card->setExpiryYear(2030);
        $card->setDefault(true);
        $this->manager->persist($card);
        $this->manager->flush();

        return $card;
    }

    private function aCustomer(): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('shopper+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);
        $this->manager->flush();

        return $customer;
    }

    private function signIn(CustomerInterface $customer): void
    {
        /** @var ShopUserInterface $user */
        $user = self::getContainer()->get('sylius.factory.shop_user')->createNew();
        $user->setCustomer($customer);
        $user->setPlainPassword('nmi-test-password');
        $user->setEnabled(true);
        $this->manager->persist($user);
        $this->manager->flush();

        $this->client->loginUser($user, 'shop');
    }

    protected function shopChannelManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    private function aPaymentMethod(): PaymentMethodInterface
    {
        $container = self::getContainer();

        // Built rather than found: continuous integration migrates an empty database, so a shop
        // page with no channel behind it answers 500 rather than the 404 this file is about.
        $this->aShopChannel();

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-own',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-own',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
            NmiGatewayFactory::CONFIG_USE_AUTHORIZE => false,
        ]);
        $this->manager->persist($gatewayConfig);

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $container->get('sylius.factory.payment_method')->createNew();
        $paymentMethod->setCode('nmi_' . bin2hex(random_bytes(4)));
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $this->manager->persist($paymentMethod);

        return $paymentMethod;
    }
}
