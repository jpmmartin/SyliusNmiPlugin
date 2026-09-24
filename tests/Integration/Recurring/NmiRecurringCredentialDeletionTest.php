<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recurring;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiRecurringCredentialRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * What deleting things does to a recurring credential. The customer's deletion lets it go, because
 * there is nobody left to renew for; the deletion of the order that opened it does not, because the
 * promise outlives the order that started it.
 */
final class NmiRecurringCredentialDeletionTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->queue()->reset();
        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    /** *The customer is deleted* — the deletion succeeds and each removal is queued, the gateway untouched. */
    public function testDeletingACustomerQueuesTheRemovalOfEachCredentialNotLetGoAlready(): void
    {
        $customer = $this->aCustomer();
        $this->aCredentialOf($customer, 'vault-renew-1');
        $this->aCredentialOf($customer, 'vault-renew-2');
        $letGo = $this->aCredentialOf($customer, 'vault-renew-3');
        $letGo->setReleasedAt(new \DateTimeImmutable());
        $this->manager->flush();
        $customerId = $customer->getId();

        $this->manager->remove($customer);
        $this->manager->flush();

        $vaultIds = array_map(static fn (PurgeStoredCard $purge): string => $purge->vaultId, $this->queuedPurges());
        sort($vaultIds);
        self::assertSame(['vault-renew-1', 'vault-renew-2'], $vaultIds, 'The one already let go had its removal queued when it was.');
        self::assertSame([], $this->gateway->operations, 'Deleting a customer must not talk to the gateway.');
        self::assertSame(0, (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM jpm_martin_sylius_nmi_recurring_credential WHERE customer_id = :id',
            ['id' => $customerId],
        ), 'The rows go with the customer.');
    }

    /** *The opening order is deleted* — the credential remains, found by its id, and can be charged. */
    public function testDeletingTheOrderThatOpenedACredentialLeavesItFindableAndChargeable(): void
    {
        $customer = $this->aCustomer();
        $credential = $this->aCredentialOf($customer, 'vault-renew-9', openedByAnOrderOf: $customer);
        $credentialId = $credential->getId();
        self::assertNotNull($credentialId);
        $method = $credential->getPaymentMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $methodId = $method->getId();
        $order = $credential->getInitialPayment()?->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);

        $this->manager->remove($order);
        $this->manager->flush();
        // As the store's next request would find it: nothing held over from the deletion.
        $this->manager->clear();

        $found = $this->credentials()->find($credentialId);
        self::assertInstanceOf(NmiRecurringCredentialInterface::class, $found);
        self::assertNull($found->getInitialPayment());
        self::assertFalse($found->isReleased());
        self::assertSame([], $this->queuedPurges(), 'Deleting the order let the credential go.');

        $method = $this->manager->find(\Sylius\Component\Core\Model\PaymentMethod::class, $methodId);
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $renewal = $this->newPaymentRequest(customer: $found->getCustomer(), paymentMethod: $method)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $renewal);
        $this->gateway->willApprove('12592803001', '109.51');

        /** @var NmiRecurringChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.recurring.charger');
        self::assertTrue($charger->charge($renewal, $found)->isApproved());
    }

    private function aCustomer(): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        return $customer;
    }

    /**
     * A credential kept for a customer. Its opening order is left without that customer unless one is
     * named: the platform refuses to delete a customer who still has orders, and the customer's
     * deletion is what is under test, not the platform's rule.
     */
    private function aCredentialOf(CustomerInterface $customer, string $vaultId, ?CustomerInterface $openedByAnOrderOf = null): NmiRecurringCredentialInterface
    {
        $paymentRequest = $this->newPaymentRequest(customer: $openedByAnOrderOf);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        // The platform's payment requests hold their payment with no rule for its deletion, so a store
        // that prunes orders removes them first; the fixture's own is not part of what is tested.
        $this->manager->remove($paymentRequest);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId($vaultId);
        $credential->setInitialTransactionId('12592792816');
        $this->manager->persist($credential);
        $this->manager->flush();

        return $credential;
    }

    private function credentials(): NmiRecurringCredentialRepositoryInterface
    {
        /** @var NmiRecurringCredentialRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_recurring_credential');

        return $repository;
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
}
