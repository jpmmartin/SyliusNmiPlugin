<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Security;

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Psr18Client;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CreatesAShopChannel;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * The four questions this change's security review has to answer, each answered by making the
 * thing happen rather than by reading the code that is supposed to prevent it.
 *
 * Most of the ownership question is answered elsewhere, and deliberately: it takes four attacks on
 * the account routes and five on the pay page, and splitting them out would leave neither file
 * readable. The one probe those do not make is here, next to the three questions about places a
 * secret can leak without anyone attacking anything.
 */
final class NmiStoredCardSecurityReviewTest extends WebTestCase
{
    use CreatesAShopChannel;

    private const VAULT_ID = 'vault-secret-1730549219';

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
     * **Is ownership enforced by construction when probed with a tampered CSRF token?**
     *
     * The neighbouring file probes a foreign identifier with no token at all; this one sends a
     * token that is present and wrong, which is the case that would tell an attacker whether the
     * identifier exists if the two checks ran the other way round. They do not: ownership is the
     * query, so a card that is not this shopper's is not a row the route can reach, and the answer
     * is the same 404 either way.
     */
    public function testAForeignCardIs404EvenWithATamperedToken(): void
    {
        $paymentMethod = $this->aPaymentMethod();
        $theirCard = $this->aCard($this->aCustomer(), $paymentMethod);
        $this->manager->flush();

        $this->signIn($this->aCustomer());

        $this->client->request(
            'POST',
            sprintf('/en_US/account/saved-cards/%d', (int) $theirCard->getId()),
            ['_method' => 'DELETE', '_csrf_token' => 'a-token-that-is-not-the-one'],
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Not 403: a foreign card is not found, rather than found and refused.');
        self::assertNotNull(
            self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card')->find($theirCard->getId()),
            'And the card is still theirs.',
        );
    }

    /**
     * **Are the three encrypted columns unreadable, including in query logs and profiler dumps?**
     *
     * At rest is asserted elsewhere. This asks the question the profiler raises: the encryption
     * listener runs on flush, so if it ran *after* the statement were built, the row would be
     * ciphertext while every query log and profiler panel held the plaintext beside it.
     */
    public function testTheEncryptedIdentifiersNeverAppearInAQueryOrItsParameters(): void
    {
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $queries, 'Without the debug data holder this test proves nothing.');
        $queries->reset();

        $card = $this->aCard($this->aCustomer(), $this->aPaymentMethod());
        $this->manager->flush();

        $recorded = json_encode($queries->getData(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString(self::VAULT_ID, $recorded, 'The vault reference is in a logged query.');
        self::assertStringNotContainsString('billing-secret', $recorded, 'The billing identifier is in a logged query.');
        self::assertStringNotContainsString('txn-secret', $recorded, 'The vaulting transaction is in a logged query.');

        // The control, and it has to be this one: a test that asserted only absences would pass
        // just as happily on a run that logged nothing at all. The insert *was* logged, and what
        // it carried in place of the three identifiers is ciphertext.
        self::assertStringContainsString(
            EncrypterInterface::ENCRYPTION_SUFFIX,
            $recorded,
            'No encrypted value was logged, so the absences above prove nothing.',
        );
        self::assertSame(self::VAULT_ID, $card->getVaultId(), 'In memory it is still the plaintext, which is what the row is written from.');
    }

    /**
     * **Can a vault identifier reach a template?**
     *
     * The account pages describe a card by the four digits a receipt already prints. The gateway's
     * own reference is never among them, and the assertion is an absence so that it stays true of
     * markup nobody has written yet.
     */
    public function testNoVaultIdentifierReachesTheAccountPages(): void
    {
        $customer = $this->aCustomer();
        $this->aCard($customer, $this->aPaymentMethod());
        $this->manager->flush();
        $this->signIn($customer);

        foreach (['/en_US/account/saved-cards', '/en_US/account/saved-cards/add'] as $path) {
            $this->client->request('GET', $path);

            // Without this the absence below would pass on an error page, which is the shape a
            // shop page takes when the test forgot to give it a channel.
            self::assertResponseIsSuccessful(sprintf('%s did not render, so the assertion below means nothing.', $path));

            self::assertStringNotContainsString(
                self::VAULT_ID,
                (string) $this->client->getResponse()->getContent(),
                sprintf('The vault reference is rendered into %s.', $path),
            );
        }
    }

    /**
     * **Can a vault identifier reach a log line or an exception message?**
     *
     * It could, and this is the finding that made the review worth running. Deleting a vault record
     * addresses it by URL, and Symfony's client writes the URL into the message it throws — so an
     * unreachable gateway put the reference into whatever logs the exception, and attaching the
     * original as the previous exception would have carried it there anyway, since a logger renders
     * the whole chain.
     */
    public function testAnUnreachableGatewayDoesNotPutTheVaultReferenceInItsMessage(): void
    {
        $client = new Psr18Client();
        $url = sprintf('https://nmi-host-that-does-not-resolve.invalid/api/v5/customers/%s', self::VAULT_ID);

        try {
            $client->sendRequest($client->createRequest('DELETE', $url));

            self::fail('That host must not resolve; the test cannot mean anything if it does.');
        } catch (\Psr\Http\Client\ClientExceptionInterface $exception) {
            self::assertStringContainsString(self::VAULT_ID, $exception->getMessage(), 'The client stopped echoing the URL, so there is nothing left to strip.');

            $ours = NmiTransportException::fromClientException($exception);

            // Everything a logger would render: the message, the chain, and the string form.
            self::assertStringNotContainsString(self::VAULT_ID, (string) $ours);
            self::assertNull($ours->getPrevious(), 'A previous exception is rendered by every logger, and this one carries the URL.');
            self::assertStringContainsString('Could not resolve host', $ours->getMessage(), 'The reason is what an operator needs, and it is kept.');
        }
    }

    /**
     * **Does the duplicate heuristic leak whether another customer holds the same card?**
     *
     * No, and by construction rather than by care: the lookup starts from the customer, so a card
     * belonging to somebody else is not a row the query can return. Asserted the way it could
     * fail — two customers, the same card, and the second one still gets to save it.
     */
    public function testOneCustomersCardIsInvisibleToAnothersDuplicateCheck(): void
    {
        $paymentMethod = $this->aPaymentMethod();
        $stranger = $this->aCustomer();
        $this->aCard($stranger, $paymentMethod);
        $this->manager->flush();

        /** @var NmiStoredCardRepositoryInterface $cards */
        $cards = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card');

        $me = $this->aCustomer();
        $this->manager->flush();

        self::assertNull(
            $cards->findOneDuplicate($me, $paymentMethod, 'visa', '1111', 10, 2035),
            'Somebody else holding this card must not read as me holding it.',
        );
        self::assertNotNull(
            $cards->findOneDuplicate($stranger, $paymentMethod, 'visa', '1111', 10, 2035),
            'And the check still finds the card for the customer who does hold it.',
        );
    }

    private function aCustomer(): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $customer->setFirstName('Ada');
        $customer->setLastName('Lovelace');
        $this->manager->persist($customer);

        return $customer;
    }

    private function signIn(CustomerInterface $customer): void
    {
        /** @var ShopUserInterface $user */
        $user = self::getContainer()->get('sylius.factory.shop_user')->createNew();
        $user->setCustomer($customer);
        $user->setPlainPassword('not-checked');
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

        $channel = $this->aShopChannel();

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-review',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-review',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
            NmiGatewayFactory::CONFIG_STORE_CARDS => true,
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

    private function aCard(CustomerInterface $customer, PaymentMethodInterface $paymentMethod): NmiStoredCardInterface
    {
        /** @var NmiStoredCardInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($customer);
        $card->setPaymentMethod($paymentMethod);
        $card->setVaultId(self::VAULT_ID);
        $card->setBillingId('billing-secret-349429273');
        $card->setVaultingTransactionId('txn-secret-12513506464');
        $card->setBrand('visa');
        $card->setLastFour('1111');
        $card->setExpiryMonth(10);
        $card->setExpiryYear(2035);
        $this->manager->persist($card);

        return $card;
    }
}
