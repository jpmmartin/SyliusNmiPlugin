<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Account;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CreatesAShopChannel;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * Adding a card from the account area, with nothing bought.
 *
 * The browser tokenises exactly as it does on the pay page; what differs is that the token goes to
 * the gateway's vault call instead of a charge, and that call reports no transaction at all — which
 * is why nothing can reach a statement.
 */
final class NmiAddStoredCardTest extends WebTestCase
{
    use CreatesAShopChannel;

    private const LIST_PATH = '/en_US/account/saved-cards';

    private const ADD_PATH = '/en_US/account/saved-cards/add';

    private const TOKEN = '00000000-000000-000000-000000000000';

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

    /** *Reaching the page.* From the account, there is a way to add a card, and it opens one. */
    public function testTheAccountOffersAWayToAddACard(): void
    {
        $this->aStoreThatSavesCards();
        $this->signIn();

        $crawler = $this->client->request('GET', self::LIST_PATH);
        self::assertResponseIsSuccessful();

        $link = $crawler->filter(sprintf('a[href="%s"]', self::ADD_PATH));
        self::assertCount(1, $link, 'The saved-cards page must offer exactly one way to add one.');

        $page = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#nmi-payment'), 'And it must open a page that can take a card.');
    }

    /**
     * *Adding a valid card.* A card is filed, and the gateway was asked to keep it rather than to
     * charge it — asserted from the operation it received, because "nothing on the statement" is
     * only true if no charge was ever made.
     */
    public function testAValidCardIsStoredWithoutCharging(): void
    {
        $this->aStoreThatSavesCards();
        $customer = $this->signIn();

        $this->client->request('GET', self::ADD_PATH);
        // The brand travels with the token, because the gateway's vault call does not report one.
        $this->client->request('POST', self::ADD_PATH, ['payment_token' => self::TOKEN, 'card_brand' => 'visa']);

        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_PATH));
        self::assertSame(['create_vault_record'], $this->gateway->operations, 'The gateway must be asked to keep the card, and nothing else.');

        $cards = $this->cardsOf($customer);
        self::assertCount(1, $cards);
        self::assertSame('1111', $cards[0]->getLastFour());
        self::assertSame('visa', $cards[0]->getBrand(), 'The only field the browser is trusted for here.');
        self::assertSame(10, $cards[0]->getExpiryMonth(), "And the expiry is still the gateway's.");
        self::assertSame(2030, $cards[0]->getExpiryYear());
        self::assertNull($cards[0]->getVaultingTransactionId(), 'Nothing was charged, so there is no transaction to cite.');
        self::assertTrue($cards[0]->isDefault(), 'A first card is the default however it was added.');
    }

    /**
     * **The gateway names no brand on this endpoint, and that is not a guess.** Creating a vault
     * record answers with the masked number and the expiry and nothing else, while the charge that
     * stores a card *does* return `card_type` — so a card added from the account area cannot be
     * described unless the browser says what it is. Before this was established the fake answered
     * with a brand nothing had sent, and this path passed a test it would have failed against the
     * real gateway: the card unfilable, and the record left at the gateway with nothing pointing
     * at it.
     */
    public function testWithoutTheBrandTheCardCannotBeDescribedAndIsNotFiled(): void
    {
        $this->aStoreThatSavesCards();
        $customer = $this->signIn();

        $this->client->request('GET', self::ADD_PATH);
        $this->client->request('POST', self::ADD_PATH, ['payment_token' => self::TOKEN]);

        self::assertCount(0, $this->cardsOf($customer));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(
            'could not be filed',
            $crawler->filter('[data-test-sylius-flash-message]')->text(),
            'The shopper is told, because the record does exist at the gateway.',
        );
    }

    /** A brand shaped like anything but a brand is not believed, and a card number least of all. */
    public function testABrandThatIsNotOneIsIgnored(): void
    {
        $this->aStoreThatSavesCards();
        $customer = $this->signIn();

        $this->client->request('GET', self::ADD_PATH);
        $this->client->request('POST', self::ADD_PATH, ['payment_token' => self::TOKEN, 'card_brand' => '4111111111111111']);

        self::assertCount(0, $this->cardsOf($customer), 'Sixteen digits are not a brand, so nothing was described and nothing was filed.');
    }

    /** *Adding a card the gateway rejects.* The reason is shown and nothing is filed. */
    public function testARejectedCardIsNotStoredAndSaysWhy(): void
    {
        $this->gateway->willFailOn('create_vault_record', NmiGatewayException::fromError(
            NmiErrorResponse::fromBody(400, json_encode([
                'type' => 'inputError',
                'error_code' => 'E_INVALID_CARD',
                'message' => 'The card number is not valid',
            ], \JSON_THROW_ON_ERROR)),
        ));

        $this->aStoreThatSavesCards();
        $customer = $this->signIn();

        $this->client->request('GET', self::ADD_PATH);
        $this->client->request('POST', self::ADD_PATH, ['payment_token' => self::TOKEN]);

        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_PATH));
        self::assertCount(0, $this->cardsOf($customer), 'A card the gateway refused is not a card.');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(
            'The card number is not valid',
            $crawler->filter('[data-test-sylius-flash-message]')->text(),
            "The shopper is told the gateway's own reason.",
        );
    }

    /** A store that never turned card saving on offers no page at all. */
    public function testAStoreThatDoesNotSaveCardsHasNoSuchPage(): void
    {
        $this->aStoreThatSavesCards(storeCards: false);
        $this->signIn();

        $this->client->request('GET', self::ADD_PATH);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    protected function shopChannelManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    /** @return list<\JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface> */
    private function cardsOf(CustomerInterface $customer): array
    {
        return self::getContainer()
            ->get('jpm_martin_sylius_nmi.repository.nmi_stored_card')
            ->findByCustomer($this->manager->find($customer::class, $customer->getId()))
        ;
    }

    private function aStoreThatSavesCards(bool $storeCards = true): PaymentMethodInterface
    {
        $container = self::getContainer();

        // Built rather than found: continuous integration migrates an empty database, so there is
        // no channel to take and every shop page would answer "Channel could not be found!".
        $channel = $this->aShopChannel();

        // **What this test asserts is about the channel's NMI methods, so it has to own them.**
        // The page answers only when the channel has exactly one NMI method that saves cards, and
        // the channel is whichever one the hostname resolves to — shared with whatever else has
        // run against this database. Behat leaves its last scenario's rows behind by design, so
        // without this the "no such page" case passes or fails on the order the suites ran in.
        foreach ($this->nmiMethodsOn($channel) as $existing) {
            $existing->removeChannel($channel);
        }

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-add',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-add',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
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
        $this->manager->flush();

        return $paymentMethod;
    }

    /**
     * The NMI payment methods already attached to a channel.
     *
     * @return list<PaymentMethodInterface>
     */
    private function nmiMethodsOn(ChannelInterface $channel): array
    {
        $nmi = [];

        /** @var PaymentMethodInterface $paymentMethod */
        foreach (self::getContainer()->get('sylius.repository.payment_method')->findAll() as $paymentMethod) {
            if (NmiGatewayFactory::NAME === $paymentMethod->getGatewayConfig()?->getFactoryName() && $paymentMethod->hasChannel($channel)) {
                $nmi[] = $paymentMethod;
            }
        }

        return $nmi;
    }

    private function signIn(): CustomerInterface
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
}
