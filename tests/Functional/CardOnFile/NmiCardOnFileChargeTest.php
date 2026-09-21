<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiCardOnFileChargerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeCardOnFileHandler;
use JpmMartin\SyliusNmiPlugin\CommandHandler\PurgeStoredCardHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * Charging a card on file from the store's own code, with nobody present — run from a kernel with no
 * request and no security token at all, which is where a message worker or a console command runs.
 */
final class NmiCardOnFileChargeTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

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

    /** *Charged from a context with no shop session.* */
    public function testTheCardIsChargedWithNobodySignedInAnywhere(): void
    {
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken(), 'The test would prove nothing with somebody signed in.');
        $this->gateway->willApprove('12584746059', '109.51');
        $payment = $this->aWaitingPaymentWithACardOnFile();

        $outcome = $this->charger()->charge($payment);

        self::assertTrue($outcome->isApproved(), sprintf('Expected approval, got %s: %s', $outcome->status, $outcome->messageKey));
        self::assertSame('12584746059', $outcome->transactionId);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
        $this->assertRecorded('12584746059', NmiTransactionInterface::TYPE_SALE);
    }

    /** *The amount is the payment's own*, and the charge is declared merchant-initiated. */
    public function testWhatTheGatewayIsAskedFor(): void
    {
        $this->gateway->willApprove('12584746059', '109.51');
        $payment = $this->aWaitingPaymentWithACardOnFile();

        $this->charger()->charge($payment, ['descriptor' => 'SHOP*ORDER']);

        $charge = $this->gateway->lastCharge;
        self::assertSame(['sale'], $this->gateway->operations);
        self::assertSame(self::AMOUNT, $charge?->amount);
        self::assertSame('USD', $charge?->currencyCode);
        self::assertSame(StoredCard::INITIATED_BY_MERCHANT, $charge?->storedCard?->initiatedBy);
        self::assertSame('1256465022', $charge?->storedCard?->vaultId);
        self::assertSame('12584742193', $charge?->storedCard?->initialTransactionId);
        self::assertNull($charge?->threeDSecure);
        self::assertSame(['descriptor' => 'SHOP*ORDER'], $charge?->extra);
    }

    public function testAPaymentWithNoCardOnFileIsRefused(): void
    {
        $payment = $this->aWaitingPayment();

        $this->assertRefusedUntouched($payment, ChargeCardOnFileHandler::NO_CARD_ON_FILE);
    }

    public function testACardWhoseAccountWasClosedIsRefused(): void
    {
        $payment = $this->aWaitingPaymentWithACardOnFile(status: NmiCardOnFileInterface::STATUS_CLOSED);

        $this->assertRefusedUntouched($payment, ChargeCardOnFileHandler::CLOSED);
    }

    public function testACardPastItsExpiryIsRefused(): void
    {
        $payment = $this->aWaitingPaymentWithACardOnFile(expiryYear: 2020);

        $this->assertRefusedUntouched($payment, ChargeCardOnFileHandler::EXPIRED);
    }

    public function testACardWithNoInitialTransactionIsRefused(): void
    {
        $payment = $this->aWaitingPaymentWithACardOnFile(initialTransactionId: null);

        $this->assertRefusedUntouched($payment, ChargeCardOnFileHandler::NO_INITIAL_TRANSACTION);
    }

    public function testAPaymentThatIsNoLongerWaitingIsRefused(): void
    {
        $payment = $this->aWaitingPaymentWithACardOnFile();
        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $this->manager->flush();

        $this->assertRefusedUntouched($payment, ChargeCardOnFileHandler::NOT_WAITING, PaymentInterface::STATE_COMPLETED);
    }

    public function testAPaymentMovedToAnotherMethodIsRefused(): void
    {
        $payment = $this->aWaitingPaymentWithACardOnFile();
        $payment->setMethod($this->anotherNmiMethod());
        $this->manager->flush();

        $this->assertRefusedUntouched($payment, ChargeCardOnFileHandler::OTHER_METHOD);
    }

    /** *A decline on the later charge.* */
    public function testADeclineLeavesThePaymentWaitingWithItsCardAndTheReasonRecorded(): void
    {
        $this->gateway->willFail(new NmiDeclinedException(NmiResponse::fromBody(json_encode([
            'object' => 'transaction', 'id' => '12584700002', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
            'response' => '2', 'response_text' => 'DECLINE', 'response_code' => '200',
        ], \JSON_THROW_ON_ERROR))));
        $payment = $this->aWaitingPaymentWithACardOnFile();

        $outcome = $this->charger()->charge($payment);

        self::assertSame(NmiChargeOutcome::DECLINED, $outcome->status);
        self::assertSame('DECLINE', $outcome->reason);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertNotNull($this->heldCardOf($payment), 'The card has to stay on file for the store to decide what next.');
        $this->assertRecorded('12584700002', NmiTransactionInterface::TYPE_SALE);
        self::assertSame(ChargeCardOnFileHandler::DECLINED, $payment->getDetails()[NmiTransactionRecorder::REFUSAL_DETAILS_KEY]['message_key'] ?? null);
    }

    /** *The gateway does not answer.* */
    public function testNoAnswerIsUnknownNotDeclined(): void
    {
        $this->gateway->willFail(new NmiTransportException('Connection timed out'));
        $payment = $this->aWaitingPaymentWithACardOnFile();

        $outcome = $this->charger()->charge($payment);

        self::assertSame(NmiChargeOutcome::UNKNOWN, $outcome->status);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertNotSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /** *Turning the setting off does not strand held orders.* */
    public function testTurningTheSettingOffDoesNotStrandAPaymentThatHoldsACard(): void
    {
        $this->gateway->willApprove('12584746059', '109.51');
        $payment = $this->aWaitingPaymentWithACardOnFile();
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method, false);

        self::assertTrue($this->charger()->charge($payment)->isApproved());
    }

    /** Charged at most once: the second attempt finds the payment no longer waiting. */
    public function testASecondChargeOfTheSamePaymentIsRefused(): void
    {
        $this->gateway->willApprove('12584746059', '109.51');
        $payment = $this->aWaitingPaymentWithACardOnFile();

        self::assertTrue($this->charger()->charge($payment)->isApproved());
        $second = $this->charger()->charge($payment);

        self::assertSame(NmiChargeOutcome::REFUSED, $second->status);
        self::assertSame(ChargeCardOnFileHandler::NOT_WAITING, $second->messageKey);
        self::assertSame(['sale'], $this->gateway->operations, 'The card was charged twice.');
    }

    /** *Released once charged* — queued once, and the row marked, after the charge is committed. */
    public function testAnApprovedChargeReleasesTheCard(): void
    {
        $this->queue()->reset();
        $this->gateway->willApprove('12584746059', '109.51');
        $payment = $this->aWaitingPaymentWithACardOnFile();
        $card = $this->heldCardOf($payment);
        self::assertNotNull($card);

        self::assertTrue($this->charger()->charge($payment)->isApproved());

        self::assertSame(['1256465022'], $this->queuedPurges());
        self::assertNotNull($card->getReleasedAt());
        self::assertNull($this->heldCardOf($payment), 'A released card is still held.');
    }

    /**
     * A charge whose handler failed is rolled back by the bus, and the charger never sees an
     * approval: the card it did not take is not released.
     */
    public function testAChargeThatDidNotGoThroughReleasesNothing(): void
    {
        $this->queue()->reset();
        $this->gateway->willFail(new \RuntimeException('The handler failed after the lock was taken.'));
        $payment = $this->aWaitingPaymentWithACardOnFile();
        $card = $this->heldCardOf($payment);
        self::assertNotNull($card);

        try {
            $this->charger()->charge($payment);
            self::fail('A handler failure has to reach the caller.');
        } catch (\Throwable) {
        }

        self::assertSame([], $this->queuedPurges());
        self::assertNull($card->getReleasedAt());
    }

    /**
     * *The gateway is unreachable during release.* The purge runs later, off the queue; when it
     * fails it throws, which is how it asks the transport to retry — and the charge it followed
     * stands.
     */
    public function testAnUnreachableGatewayDuringReleaseLeavesTheChargeStandingAndThePurgeRetried(): void
    {
        $this->queue()->reset();
        $this->gateway->willApprove('12584746059', '109.51');
        $payment = $this->aWaitingPaymentWithACardOnFile();
        self::assertTrue($this->charger()->charge($payment)->isApproved());

        $this->gateway->willFailOn('delete_vault_record', new NmiTransportException('Connection timed out'));
        [$purge] = $this->queuedPurgeMessages();

        /** @var PurgeStoredCardHandler $handler */
        $handler = self::getContainer()->get('jpm_martin_sylius_nmi.command_handler.purge_stored_card');

        try {
            $handler($purge);
            self::fail('A purge the gateway did not take has to throw, or the transport will not retry it.');
        } catch (NmiTransportException) {
        }

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(['1256465022'], $this->gateway->deletedVaultIds);
    }

    /** @return list<string> */
    private function queuedPurges(): array
    {
        return array_map(static fn (PurgeStoredCard $purge): string => $purge->vaultId, $this->queuedPurgeMessages());
    }

    /** @return list<PurgeStoredCard> */
    private function queuedPurgeMessages(): array
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

    private function assertRefusedUntouched(PaymentInterface $payment, string $messageKey, string $state = PaymentInterface::STATE_PROCESSING): void
    {
        $outcome = $this->charger()->charge($payment);

        self::assertSame(NmiChargeOutcome::REFUSED, $outcome->status);
        self::assertSame($messageKey, $outcome->messageKey);
        self::assertSame([], $this->gateway->operations, 'The gateway was contacted.');
        self::assertSame($state, $payment->getState());
    }

    private function aWaitingPayment(): PaymentInterface
    {
        $payment = $this->newPaymentRequest()->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $payment->setState(PaymentInterface::STATE_PROCESSING);
        $this->manager->flush();

        return $payment;
    }

    private function aWaitingPaymentWithACardOnFile(
        ?string $initialTransactionId = '12584742193',
        string $status = NmiCardOnFileInterface::STATUS_ACTIVE,
        int $expiryYear = 2031,
    ): PaymentInterface {
        $payment = $this->aWaitingPayment();
        $this->aCardOnFileFor($payment, $initialTransactionId, $status, $expiryYear);

        return $payment;
    }

    private function anotherNmiMethod(): PaymentMethodInterface
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-other',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-other',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
        ]);
        $gatewayConfig->setUsePayum(false);

        $method = new PaymentMethod();
        $method->setCode('nmi_other_' . bin2hex(random_bytes(4)));
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Other card');
        $method->setGatewayConfig($gatewayConfig);
        $this->manager->persist($gatewayConfig);
        $this->manager->persist($method);
        $this->manager->flush();

        return $method;
    }

    private function charger(): NmiCardOnFileChargerInterface
    {
        /** @var NmiCardOnFileChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.card_on_file.charger');

        return $charger;
    }

    private function assertRecorded(string $transactionId, string $type): void
    {
        /** @var \JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        self::assertSame($type, $transactions->findOneByAnyTransactionId($transactionId)?->getType());
    }
}
