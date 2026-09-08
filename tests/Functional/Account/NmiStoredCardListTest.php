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

/**
 * The saved-cards page in the shopper's account.
 *
 * Two things are asserted that reading the code cannot establish: that the page is behind the
 * shop's ROLE_USER rule, and that no card number reaches the rendered HTML.
 */
final class NmiStoredCardListTest extends WebTestCase
{
    private const PATH = '/en_US/account/saved-cards';

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

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

    /**
     * The route is mounted under the locale segment on purpose: Sylius's access_control rule reads
     * `^/(?!admin|api…)[^/]++/account`, so without it this page would sit inside the shop firewall
     * but outside the rule that demands a signed-in shopper.
     */
    public function testAVisitorWhoIsNotSignedInCannotReachIt(): void
    {
        $this->client->request('GET', self::PATH);

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'An anonymous visitor must be sent to the login page, not shown the account area.',
        );
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheShopperSeesTheirOwnCardsDescribedButNeverTheNumber(): void
    {
        $customer = $this->aSignedInCustomer();
        $method = $this->aPaymentMethod();
        $this->aCard($customer, $method, '4242', true);
        $this->manager->flush();

        $crawler = $this->client->request('GET', self::PATH);
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('4242', $html);
        self::assertStringContainsString('visa', $html);

        // The store holds no card number, and the page must not invent one: sixteen consecutive
        // digits anywhere in the markup would mean something reconstructed one.
        self::assertDoesNotMatchRegularExpression('/\d{13,19}/', strip_tags($html), 'Something rendered a full card number.');

        self::assertCount(1, $crawler->filter('[data-test-nmi-stored-card-default]'));
    }

    /** An empty account says so rather than rendering an empty page. */
    public function testAnAccountWithNoCardsSaysSo(): void
    {
        $this->aSignedInCustomer();
        $this->manager->flush();

        $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no saved cards', (string) $this->client->getResponse()->getContent());
    }

    /** 4.1: the account menu carries the entry, and it points at this page. */
    public function testTheAccountMenuOffersTheWayIn(): void
    {
        $this->aSignedInCustomer();
        $this->manager->flush();

        $crawler = $this->client->request('GET', '/en_US/account/dashboard');
        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter(sprintf('a[href="%s"]', self::PATH)),
            'The account menu must offer exactly one way into the saved cards.',
        );
    }

    private function aSignedInCustomer(): CustomerInterface
    {
        $container = self::getContainer();

        /** @var CustomerInterface $customer */
        $customer = $container->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $customer->setFirstName('Ada');
        $customer->setLastName('Lovelace');
        $this->manager->persist($customer);

        /** @var ShopUserInterface $user */
        $user = $container->get('sylius.factory.shop_user')->createNew();
        $user->setCustomer($customer);
        $user->setPlainPassword('nmi-test-password');
        $user->setEnabled(true);
        $this->manager->persist($user);
        $this->manager->flush();

        $this->client->loginUser($user, 'shop');

        return $customer;
    }

    private function aPaymentMethod(): PaymentMethodInterface
    {
        $container = self::getContainer();

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-account']);
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

    private function aCard(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        string $lastFour,
        bool $default = false,
    ): NmiStoredCardInterface {
        /** @var NmiStoredCardInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($customer);
        $card->setPaymentMethod($paymentMethod);
        $card->setVaultId('vault-' . $lastFour);
        $card->setBillingId('billing-' . $lastFour);
        $card->setBrand('visa');
        $card->setLastFour($lastFour);
        $card->setExpiryMonth(10);
        $card->setExpiryYear(2030);
        $card->setDefault($default);
        $this->manager->persist($card);

        return $card;
    }
}
