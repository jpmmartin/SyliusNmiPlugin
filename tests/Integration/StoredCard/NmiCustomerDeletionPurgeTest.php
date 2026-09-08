<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\StoredCard;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\CommandHandler\PurgeStoredCardHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * Deleting a customer, and what it owes the gateway.
 *
 * The requirement has two halves that pull against each other: the cards must be forgotten at the
 * gateway, and a gateway that is down must not stop a customer being deleted. Queuing is what
 * satisfies both — so what is asserted here is that the deletion does not talk to the gateway at
 * all, and that what it queues does.
 */
final class NmiCustomerDeletionPurgeTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->queue()->reset();

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /**
     * *Deleting a customer purges the gateway too*, first half.
     *
     * The rows go by a database cascade, so nothing in this plugin removes them — which is exactly
     * why the identifiers have to be read before the delete rather than after it.
     */
    public function testDeletingACustomerRemovesTheirCardsAndQueuesAPurgeForEach(): void
    {
        $customer = $this->aCustomer();
        $paymentMethod = $this->aPaymentMethod();
        $this->aCard($customer, $paymentMethod, '1111');
        $this->aCard($customer, $paymentMethod, '4242');
        $this->manager->flush();

        self::assertCount(2, $this->cardsOf($customer));

        $this->manager->remove($customer);
        $this->manager->flush();

        self::assertCount(0, $this->cardsOf($customer), 'The rows go with the customer.');

        $purges = $this->queuedPurges();
        self::assertCount(2, $purges);

        // Compared as a set: the cards come back in the order the account page lists them, newest
        // first, and nothing about a purge depends on which one is forgotten first.
        $vaultIds = array_map(static fn (PurgeStoredCard $purge): string => $purge->vaultId, $purges);
        sort($vaultIds);
        self::assertSame(['vault-1111', 'vault-4242'], $vaultIds);

        self::assertSame(
            [$paymentMethod->getCode(), $paymentMethod->getCode()],
            array_map(static fn (PurgeStoredCard $purge): string => $purge->paymentMethodCode, $purges),
        );
    }

    /**
     * *The gateway is unreachable during deletion.*
     *
     * The gateway cannot refuse a deletion it is never asked about, and this is the assertion that
     * says so: the fake refuses everything, and the deletion succeeds having recorded no operation
     * at all. The purge is still queued, which is the other half of the promise.
     */
    public function testAnUnreachableGatewayDoesNotStopTheDeletion(): void
    {
        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(503));

        $customer = $this->aCustomer();
        $this->aCard($customer, $this->aPaymentMethod(), '1111');
        $this->manager->flush();

        $this->manager->remove($customer);
        $this->manager->flush();

        self::assertCount(0, $this->cardsOf($customer));
        self::assertSame([], $this->gateway->operations, 'Deleting a customer must not talk to the gateway.');
        self::assertCount(1, $this->queuedPurges());
    }

    /** A customer with no cards queues nothing — the listener is silent where it has nothing to do. */
    public function testDeletingACustomerWithNoCardsQueuesNothing(): void
    {
        $customer = $this->aCustomer();
        $this->manager->flush();

        $this->manager->remove($customer);
        $this->manager->flush();

        self::assertSame([], $this->queuedPurges());
    }

    /** The handler's whole job: the vault record the store has already forgotten goes too. */
    public function testHandlingThePurgeForgetsTheRecordAtTheGateway(): void
    {
        $paymentMethod = $this->aPaymentMethod();
        $this->manager->flush();

        $this->handle(new PurgeStoredCard('vault-1111', (string) $paymentMethod->getCode()));

        self::assertSame(['delete_vault_record'], $this->gateway->operations);
        self::assertSame(['vault-1111'], $this->gateway->deletedVaultIds);
    }

    /**
     * *…and the purge is retried until it completes rather than dropped.*
     *
     * Retrying is the transport's job, and the only way to ask for it is to throw. A handler that
     * swallowed this would leave a card at the gateway that nothing in the store can point at
     * again — so the assertion is that it does not catch.
     */
    public function testAFailedPurgeThrowsSoTheTransportRetriesIt(): void
    {
        $paymentMethod = $this->aPaymentMethod();
        $this->manager->flush();

        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(503));

        $this->expectException(NmiTransportException::class);

        $this->handle(new PurgeStoredCard('vault-1111', (string) $paymentMethod->getCode()));
    }

    /**
     * A record the gateway no longer has is the state being asked for.
     *
     * Established against the sandbox rather than assumed: the first delete answers 204, the second
     * 404 with `E_RESOURCE_NOT_FOUND`. Without this the retry would loop for ever on a purge that
     * had already succeeded.
     */
    public function testARecordTheGatewayNoLongerHasCountsAsPurged(): void
    {
        $paymentMethod = $this->aPaymentMethod();
        $this->manager->flush();

        $this->gateway->willFail(NmiGatewayException::fromError(
            NmiErrorResponse::fromBody(404, '{"type":"inputError","error_code":"E_RESOURCE_NOT_FOUND","message":"Customer Vault not found"}') ?? throw new \LogicException('Unreadable fixture.'),
        ));

        $this->handle(new PurgeStoredCard('vault-1111', (string) $paymentMethod->getCode()));

        self::assertTrue(true, 'Nothing was thrown, so the message is acknowledged rather than retried for ever.');
    }

    /**
     * A payment method that no longer exists carries the credentials this record could have been
     * removed with, so no number of retries will ever succeed. It is given up on rather than
     * queued for ever — and said out loud in the log, because a person has to clear it by hand.
     */
    public function testAPurgeForAPaymentMethodThatIsGoneIsNotRetriedForEver(): void
    {
        $this->handle(new PurgeStoredCard('vault-1111', 'nmi_this_code_names_nothing'));

        self::assertSame([], $this->gateway->operations);
    }

    private function handle(PurgeStoredCard $command): void
    {
        /** @var PurgeStoredCardHandler $handler */
        $handler = self::getContainer()->get('jpm_martin_sylius_nmi.command_handler.purge_stored_card');

        $handler($command);
    }

    /** @return list<PurgeStoredCard> */
    private function queuedPurges(): array
    {
        $purges = [];
        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof PurgeStoredCard) {
                $purges[] = $message;
            }
        }

        return $purges;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }

    /** @return list<NmiStoredCardInterface> */
    private function cardsOf(CustomerInterface $customer): array
    {
        /** @var NmiStoredCardRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card');

        return $repository->findByCustomer($customer);
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

    private function aPaymentMethod(): PaymentMethodInterface
    {
        $container = self::getContainer();

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-account',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-account',
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
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

    private function aCard(CustomerInterface $customer, PaymentMethodInterface $paymentMethod, string $lastFour): NmiStoredCardInterface
    {
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
        $this->manager->persist($card);

        return $card;
    }
}
