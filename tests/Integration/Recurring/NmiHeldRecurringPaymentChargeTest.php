<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recurring;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiCardOnFileChargerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeCardOnFileHandler;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeRecurringCredentialHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * A held payment that opened recurring charges kept its card as a recurring credential, not on file.
 * A store that already charges its held orders through the card-on-file charger keeps doing so, and
 * the charge goes through the credential — which it does not let go.
 */
final class NmiHeldRecurringPaymentChargeTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const FIRST_TRANSACTION = '12592792407';

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

    /** *A held payment kept on a recurring credential*, charged by the store's existing code. */
    public function testTheCardOnFileChargerChargesAHeldPaymentThroughItsCredential(): void
    {
        [$payment, $credential] = $this->aHeldPaymentKeptOnACredential();
        $this->gateway->willApprove('12592802001', '109.51');

        $outcome = $this->cardOnFileCharger()->charge($payment, ['descriptor' => 'SHOP*ORDER']);

        self::assertTrue($outcome->isApproved(), sprintf('Expected approval, got %s: %s', $outcome->status, $outcome->messageKey));
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        $charge = $this->gateway->lastCharge;
        self::assertSame(StoredCard::INITIATED_BY_MERCHANT, $charge?->storedCard?->initiatedBy);
        self::assertSame(self::FIRST_TRANSACTION, $charge?->storedCard?->initialTransactionId);
        self::assertSame('1736036779', $charge?->storedCard?->vaultId);
        self::assertSame(['descriptor' => 'SHOP*ORDER'], $charge?->extra);
        $this->assertRecordedAgainst($payment, '12592802001');
        self::assertSame([NmiRecurringChargerInterface::ACTION], $this->actionsRecordedOn($payment), 'Recorded under the recurring charge, which is what it was.');
        self::assertFalse($credential->isReleased(), 'The renewals the credential was kept for are still to come.');
        self::assertSame([], $this->queue()->getSent(), 'Nothing may be queued for removal at the gateway.');
    }

    /** *A held payment charged through its recurring credential* — declined, it keeps its credential. */
    public function testADeclineLeavesTheHeldPaymentWaitingWithItsCredential(): void
    {
        [$payment, $credential] = $this->aHeldPaymentKeptOnACredential();
        $this->gateway->willFail(new NmiDeclinedException(NmiResponse::fromBody((string) json_encode([
            'object' => 'transaction', 'id' => '12592802002', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
            'response' => '2', 'response_text' => 'DECLINE', 'response_code' => '200',
        ]))));

        $outcome = $this->cardOnFileCharger()->charge($payment);

        self::assertSame(NmiChargeOutcome::DECLINED, $outcome->status);
        self::assertSame(ChargeRecurringCredentialHandler::DECLINED, $outcome->messageKey);
        self::assertSame('DECLINE', $outcome->reason);
        self::assertSame(200, $outcome->code);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertFalse($credential->isReleased());
    }

    /** *A held payment whose recurring credential was let go.* */
    public function testAHeldPaymentWhoseCredentialWasLetGoIsRefusedAsHoldingNoCard(): void
    {
        [$payment, $credential] = $this->aHeldPaymentKeptOnACredential();
        $credential->setReleasedAt(new \DateTimeImmutable());
        $this->manager->flush();

        $outcome = $this->cardOnFileCharger()->charge($payment);

        self::assertSame(NmiChargeOutcome::REFUSED, $outcome->status);
        self::assertSame(ChargeCardOnFileHandler::NO_CARD_ON_FILE, $outcome->messageKey);
        self::assertSame([], $this->gateway->operations, 'The gateway was contacted.');
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
    }

    /** The door for held payments charges only held payments: a renewal is the recurring charger's. */
    public function testARenewalIsNotChargedThroughTheCardOnFileDoor(): void
    {
        [$held, $credential] = $this->aHeldPaymentKeptOnACredential();
        $method = $held->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $renewal = $this->newPaymentRequest(customer: $credential->getCustomer(), paymentMethod: $method)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $renewal);

        $outcome = $this->cardOnFileCharger()->charge($renewal);

        self::assertSame(NmiChargeOutcome::REFUSED, $outcome->status);
        self::assertSame([], $this->gateway->operations, 'The gateway was contacted.');
    }

    /** A payment holding a card on file is charged as before, whatever the recurring charges policy. */
    public function testACardOnFileIsStillChargedAsACardOnFile(): void
    {
        $payment = $this->newPaymentRequest()->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $this->aCardOnFileFor($payment);
        $this->gateway->willApprove('12592802003', '109.51');

        $outcome = $this->cardOnFileCharger()->charge($payment);

        self::assertTrue($outcome->isApproved());
        self::assertSame(NmiChargeOutcome::approved('12592802003')->messageKey, $outcome->messageKey, 'Told as a card on file\'s charge.');
        self::assertContains(NmiCardOnFileChargerInterface::ACTION, $this->actionsRecordedOn($payment));
        self::assertNotContains(NmiRecurringChargerInterface::ACTION, $this->actionsRecordedOn($payment));
    }

    /** @return array{PaymentInterface, NmiRecurringCredentialInterface} */
    private function aHeldPaymentKeptOnACredential(): array
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $paymentRequest = $this->newPaymentRequest(customer: $customer);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $payment->setState(PaymentInterface::STATE_PROCESSING);
        // The checkout's own request is not the charge's record, and would muddy the list of actions.
        $this->manager->remove($paymentRequest);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId(self::FIRST_TRANSACTION);
        $credential->setBrand('visa');
        $credential->setLastFour('1111');
        $credential->setExpiryMonth(10);
        $credential->setExpiryYear(2035);
        $this->manager->persist($credential);
        $this->manager->flush();

        return [$payment, $credential];
    }

    /** @return list<string> */
    private function actionsRecordedOn(PaymentInterface $payment): array
    {
        return array_values(array_map(
            static fn ($request): string => $request->getAction(),
            self::getContainer()->get('sylius.repository.payment_request')->findBy(['payment' => $payment]),
        ));
    }

    private function cardOnFileCharger(): NmiCardOnFileChargerInterface
    {
        /** @var NmiCardOnFileChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.card_on_file.charger');

        return $charger;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }

    private function assertRecordedAgainst(PaymentInterface $payment, string $transactionId): void
    {
        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        $transaction = $transactions->findOneByAnyTransactionId($transactionId);
        self::assertSame(NmiTransactionInterface::TYPE_SALE, $transaction?->getType());
        self::assertSame($payment->getId(), $transaction?->getPayment()?->getId());
    }
}
