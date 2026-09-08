<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Account;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CreatesAShopChannel;

/**
 * The saved-cards page in the shopper's account.
 *
 * Two things are asserted that reading the code cannot establish: that the page is behind the
 * shop's ROLE_USER rule, and that no card number reaches the rendered HTML.
 */
final class NmiStoredCardListTest extends WebTestCase
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
        // A channel with no payment method on it: the page is about having no cards, and a shop
        // page with no channel behind it does not render at all.
        $this->aShopChannel();
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
        $this->aPaymentMethod();
        $this->manager->flush();

        $crawler = $this->client->request('GET', '/en_US/account/dashboard');
        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter(sprintf('a[href="%s"]', self::PATH)),
            'The account menu must offer exactly one way into the saved cards.',
        );
    }

    /**
     * *Card saving left disabled*, in the one place it is easiest to forget.
     *
     * A store that never turns card saving on is promised no observable change in the storefront,
     * and an extra entry in every shopper's account menu is about as observable as it gets. It also
     * spares a store that skipped the optional account routes a menu that cannot render: KnpMenu
     * resolves the route when the page is drawn, and a missing one takes the account area with it.
     */
    public function testAStoreThatSavesNoCardsOffersNoSuchMenuEntry(): void
    {
        $this->aSignedInCustomer();
        $this->aPaymentMethod(storeCards: false);
        $this->manager->flush();

        $crawler = $this->client->request('GET', '/en_US/account/dashboard');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter(sprintf('a[href="%s"]', self::PATH)));
    }

    /** 4.5: choosing another card moves the default, and only one holds it at a time. */
    public function testChoosingAnotherCardMovesTheDefault(): void
    {
        $customer = $this->aSignedInCustomer();
        $method = $this->aPaymentMethod();
        $first = $this->aCard($customer, $method, '1111', true);
        $second = $this->aCard($customer, $method, '2222');
        $this->manager->flush();

        // Submitted as the page renders it, tokens and method override included, rather than
        // assembled here — a request this test built could pass while the markup was wrong.
        $crawler = $this->client->request('GET', self::PATH);
        $this->client->submit($crawler->filter(sprintf('[data-test-nmi-stored-card-make-default="%d"]', $second->getId()))->form());

        self::assertTrue($this->client->getResponse()->isRedirect());
        // Re-read rather than refresh: the request cycle has its own unit of work, so the objects
        // this test built are no longer the ones the controller changed.
        self::assertTrue($this->reload($second)?->isDefault(), 'The chosen card must become the default.');
        self::assertFalse($this->reload($first)?->isDefault(), 'And the previous one must stop being it.');
    }

    /** The default card is not offered the button that would make it the default again. */
    public function testTheDefaultCardIsNotOfferedTheChoiceItAlreadyHolds(): void
    {
        $customer = $this->aSignedInCustomer();
        $method = $this->aPaymentMethod();
        $default = $this->aCard($customer, $method, '1111', true);
        $this->manager->flush();

        $crawler = $this->client->request('GET', self::PATH);

        self::assertCount(0, $crawler->filter(sprintf('[data-test-nmi-stored-card-make-default="%d"]', $default->getId())));
    }

    /** 4.6: the row goes and so does the gateway's record. */
    public function testDeletingACardForgetsItAtTheGatewayToo(): void
    {
        $customer = $this->aSignedInCustomer();
        $method = $this->aPaymentMethod();
        $card = $this->aCard($customer, $method, '1111', true);
        $this->manager->flush();
        $id = (int) $card->getId();

        $crawler = $this->client->request('GET', self::PATH);
        $this->client->submit($this->deleteFormFor($crawler, $id));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertContains('vault-1111', $this->gateway->deletedVaultIds, 'The gateway must be told to forget it.');
        self::assertNull(
            self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card')->find($id),
            'The row must be gone.',
        );
    }

    /** And the default does not simply vanish with it. */
    public function testDeletingTheDefaultElectsAnother(): void
    {
        $customer = $this->aSignedInCustomer();
        $method = $this->aPaymentMethod();
        $default = $this->aCard($customer, $method, '1111', true);
        $other = $this->aCard($customer, $method, '2222');
        $this->manager->flush();

        $crawler = $this->client->request('GET', self::PATH);
        $this->client->submit($this->deleteFormFor($crawler, (int) $default->getId()));

        self::assertTrue($this->reload($other)?->isDefault(), 'A survivor must take over as the default.');
    }

    /**
     * A gateway that refuses leaves both halves intact. Deleting the row anyway would strand the
     * vault record with nothing left pointing at it, so nobody could ever remove it.
     */
    public function testAGatewayThatRefusesLeavesTheCardWhereItIs(): void
    {
        $this->gateway->willFailOn('delete_vault_record', NmiGatewayException::fromHttpStatus(400));

        $card = $this->aCardOfMyOwn();
        $crawler = $this->client->request('GET', self::PATH);
        $this->client->submit($this->deleteFormFor($crawler, (int) $card->getId()));

        self::assertNotNull($this->reload($card), 'Nothing may be deleted when the gateway refused.');
    }

    /** Unreachable is not refused: whether the record is gone is unknown, so nothing moves. */
    public function testAnUnreachableGatewayLeavesTheCardWhereItIs(): void
    {
        $this->gateway->willFailOn('delete_vault_record', NmiTransportException::fromInconclusiveStatus(503));

        $card = $this->aCardOfMyOwn();
        $crawler = $this->client->request('GET', self::PATH);
        $this->client->submit($this->deleteFormFor($crawler, (int) $card->getId()));

        self::assertNotNull($this->reload($card));
    }

    /** A record the gateway no longer has is the state being asked for, so the row may go. */
    public function testACardTheGatewayAlreadyForgotIsStillDeletedHere(): void
    {
        $this->gateway->willFailOn('delete_vault_record', NmiGatewayException::fromHttpStatus(404));

        $card = $this->aCardOfMyOwn();
        $id = (int) $card->getId();
        $crawler = $this->client->request('GET', self::PATH);
        $this->client->submit($this->deleteFormFor($crawler, $id));

        self::assertNull(
            self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card')->find($id),
            'A vault record that is already gone is a deletion that already happened.',
        );
    }

    protected function shopChannelManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    private function aCardOfMyOwn(): NmiStoredCardInterface
    {
        $customer = $this->aSignedInCustomer();
        $card = $this->aCard($customer, $this->aPaymentMethod(), '1111', true);
        $this->manager->flush();

        return $card;
    }

    private function reload(NmiStoredCardInterface $card): ?NmiStoredCardInterface
    {
        /** @var NmiStoredCardInterface|null $reloaded */
        $reloaded = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card')->find($card->getId());

        return $reloaded;
    }

    private function deleteFormFor(\Symfony\Component\DomCrawler\Crawler $crawler, int $id): \Symfony\Component\DomCrawler\Form
    {
        return $crawler
            ->filter(sprintf('[data-test-nmi-stored-card="%d"] [data-test-button="delete"]', $id))
            ->form()
        ;
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

    private function aPaymentMethod(bool $storeCards = true): PaymentMethodInterface
    {
        $container = self::getContainer();

        // Built rather than found: continuous integration has no fixtures, so a test that took
        // whichever channel existed found none and every shop page answered "Channel could not be
        // found!". The account menu also asks whether *this channel* saves cards, so the test has
        // to own the channel's NMI methods rather than inherit whatever else ran against this
        // database — Behat leaves its last scenario's rows behind by design.
        $channel = $this->aShopChannel();
        /** @var PaymentMethodInterface $existing */
        foreach ($container->get('sylius.repository.payment_method')->findAll() as $existing) {
            if (NmiGatewayFactory::NAME === $existing->getGatewayConfig()?->getFactoryName() && $existing->hasChannel($channel)) {
                $existing->removeChannel($channel);
            }
        }

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        // A complete configuration, as a store has: an incomplete one is a different failure and
        // must not be what these tests are exercising.
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-account',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-account',
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            NmiGatewayFactory::CONFIG_USE_AUTHORIZE => false,
            NmiGatewayFactory::CONFIG_STORE_CARDS => $storeCards,
        ]);
        $this->manager->persist($gatewayConfig);

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $container->get('sylius.factory.payment_method')->createNew();
        $paymentMethod->setCode('nmi_' . bin2hex(random_bytes(4)));
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);
        $paymentMethod->addChannel($channel);
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
