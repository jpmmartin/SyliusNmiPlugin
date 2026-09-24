<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recurring;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeRecurringCredentialHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringCredentialReleaserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *Letting a recurring credential go*, which is the store's call: the stored card's removal is queued
 * on the same transport that forgets any card the store no longer holds, and the credential is
 * refused from then on.
 */
final class NmiRecurringCredentialReleaseTest extends KernelTestCase
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

        $this->manager->beginTransaction();
        $this->queue()->reset();
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

    /** *Let go by the store.* */
    public function testLettingGoQueuesTheRemovalOfTheStoredCardOnce(): void
    {
        $credential = $this->aCredential();

        $this->releaser()->release($credential);

        $purges = $this->queuedPurges();
        self::assertCount(1, $purges);
        self::assertSame('1736036779', $purges[0]->vaultId);
        self::assertSame($credential->getPaymentMethod()?->getCode(), $purges[0]->paymentMethodCode);
        self::assertTrue($credential->isReleased());
        $this->manager->refresh($credential);
        self::assertTrue($credential->isReleased(), 'Marked in memory only.');
    }

    /** *Let go twice.* */
    public function testLettingGoTwiceChangesNothing(): void
    {
        $credential = $this->aCredential();
        $this->releaser()->release($credential);
        $releasedAt = $credential->getReleasedAt();

        $this->releaser()->release($credential);

        self::assertCount(1, $this->queuedPurges(), 'A second removal was queued.');
        self::assertSame($releasedAt, $credential->getReleasedAt());
    }

    /** Every later charge through a credential let go is refused, and the gateway is not asked. */
    public function testAChargeAfterLettingGoIsRefusedAsLetGo(): void
    {
        $credential = $this->aCredential();
        $this->releaser()->release($credential);
        $method = $credential->getPaymentMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $renewal = $this->newPaymentRequest(customer: $credential->getCustomer(), paymentMethod: $method)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $renewal);

        /** @var NmiRecurringChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.recurring.charger');
        $outcome = $charger->charge($renewal, $credential);

        self::assertSame(NmiChargeOutcome::REFUSED, $outcome->status);
        self::assertSame(ChargeRecurringCredentialHandler::RELEASED, $outcome->messageKey);
        self::assertSame([], $this->gateway->operations, 'The gateway was contacted.');
        self::assertSame(PaymentInterface::STATE_NEW, $renewal->getState());
    }

    private function aCredential(): NmiRecurringCredentialInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $payment = $this->newPaymentRequest(customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId('12592792816');
        $this->manager->persist($credential);
        $this->manager->flush();

        return $credential;
    }

    private function releaser(): NmiRecurringCredentialReleaserInterface
    {
        /** @var NmiRecurringCredentialReleaserInterface $releaser */
        $releaser = self::getContainer()->get('test.jpm_martin_sylius_nmi.recurring.releaser');

        return $releaser;
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
