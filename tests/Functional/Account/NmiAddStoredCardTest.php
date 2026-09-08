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

/**
 * Adding a card from the account area, with nothing bought.
 *
 * The browser tokenises exactly as it does on the pay page; what differs is that the token goes to
 * the gateway's vault call instead of a charge, and that call reports no transaction at all — which
 * is why nothing can reach a statement.
 */
final class NmiAddStoredCardTest extends WebTestCase
{
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
        $this->client->request('POST', self::ADD_PATH, ['payment_token' => self::TOKEN]);

        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_PATH));
        self::assertSame(['create_vault_record'], $this->gateway->operations, 'The gateway must be asked to keep the card, and nothing else.');

        $cards = $this->cardsOf($customer);
        self::assertCount(1, $cards);
        self::assertSame('1111', $cards[0]->getLastFour());
        self::assertNull($cards[0]->getVaultingTransactionId(), 'Nothing was charged, so there is no transaction to cite.');
        self::assertTrue($cards[0]->isDefault(), 'A first card is the default however it was added.');
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

        /** @var ChannelInterface $channel */
        $channel = $container->get('sylius.repository.channel')->findOneBy([]);

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-add',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-add',
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
        $this->manager->flush();

        return $paymentMethod;
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
