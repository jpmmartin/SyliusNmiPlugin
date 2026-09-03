<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Recorder;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransaction;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Factory\FactoryInterface;

final class NmiTransactionRecorderTest extends TestCase
{
    private NmiTransactionRepositoryInterface&MockObject $repository;

    private ObjectManager&MockObject $manager;

    private NmiTransactionRecorder $recorder;

    protected function setUp(): void
    {
        /** @var FactoryInterface<NmiTransactionInterface>&MockObject $factory */
        $factory = $this->createMock(FactoryInterface::class);
        $factory->method('createNew')->willReturnCallback(static fn (): NmiTransaction => new NmiTransaction());

        $this->repository = $this->createMock(NmiTransactionRepositoryInterface::class);
        $this->manager = $this->createMock(ObjectManager::class);

        $this->recorder = new NmiTransactionRecorder($factory, $this->repository, $this->manager, new NmiAmountFormatter());
    }

    public function testItRecordsASaleAgainstThePayment(): void
    {
        $payment = $this->payment();
        $this->manager->expects(self::once())->method('persist');

        $transaction = $this->recorder->record($payment, $this->response(), NmiTransactionInterface::TYPE_SALE);

        self::assertSame('12513506464', $transaction->getTransactionId());
        self::assertSame(NmiTransactionInterface::TYPE_SALE, $transaction->getType());
        self::assertSame(911, $transaction->getAmount());
        self::assertSame('USD', $transaction->getCurrencyCode());
        self::assertSame('123456', $transaction->getAuthCode());
        self::assertSame($payment, $transaction->getPayment());
        self::assertNull($transaction->getParentTransactionId());
    }

    /** The gateway states a refund as a negative amount; keeping the sign is what makes it summable. */
    public function testARefundKeepsItsSignAndNamesWhatItRefunds(): void
    {
        $transaction = $this->recorder->record(
            $this->payment(),
            $this->response(id: '12513495754', amount: '-3.11', authCode: null),
            NmiTransactionInterface::TYPE_REFUND,
            parentTransactionId: '12513495450',
        );

        self::assertSame(-311, $transaction->getAmount());
        self::assertSame('12513495754', $transaction->getTransactionId());
        self::assertSame('12513495450', $transaction->getParentTransactionId());
    }

    /** A capture reuses its authorisation's id, so only the pair with the type is unique. */
    public function testACaptureReusesTheAuthorisationsId(): void
    {
        $transaction = $this->recorder->record($this->payment(), $this->response(), NmiTransactionInterface::TYPE_CAPTURE);

        self::assertSame('12513506464', $transaction->getTransactionId());
        self::assertSame(NmiTransactionInterface::TYPE_CAPTURE, $transaction->getType());
        self::assertNull($transaction->getParentTransactionId());
    }

    /** A replayed answer must not double the log — the pair is unique in the schema. */
    public function testRecordingTheSameOperationTwiceWritesOneRow(): void
    {
        $existing = new NmiTransaction();
        $existing->setTransactionId('12513506464');
        $existing->setType(NmiTransactionInterface::TYPE_SALE);

        $this->repository->method('findOneByTransactionIdAndType')->willReturn($existing);
        $this->manager->expects(self::never())->method('persist');

        $transaction = $this->recorder->record($this->payment(), $this->response(), NmiTransactionInterface::TYPE_SALE);

        self::assertSame($existing, $transaction);
    }

    /** The admin screen reads only the payment, so the copy is written even on a replay. */
    public function testTheDenormalisedCopyIsWrittenOnAReplayToo(): void
    {
        $existing = new NmiTransaction();
        $this->repository->method('findOneByTransactionIdAndType')->willReturn($existing);

        $payment = $this->payment();
        $this->recorder->record($payment, $this->response(), NmiTransactionInterface::TYPE_SALE);

        self::assertArrayHasKey(NmiTransactionRecorder::DETAILS_KEY, $payment->getDetails());
    }

    public function testItWritesTheAdminCopyOntoThePaymentWithoutDisturbingWhatIsThere(): void
    {
        $payment = $this->payment();
        $payment->setDetails(['some_other_plugin' => ['keep' => 'me']]);

        $this->recorder->record($payment, $this->response(), NmiTransactionInterface::TYPE_SALE);

        $details = $payment->getDetails();
        self::assertSame(['keep' => 'me'], $details['some_other_plugin']);

        $nmi = $details[NmiTransactionRecorder::DETAILS_KEY];
        self::assertSame('12513506464', $nmi['transaction_id']);
        self::assertSame('sale', $nmi['type']);
        self::assertSame('pendingsettlement', $nmi['status']);
        self::assertSame(100, $nmi['response_code']);
        self::assertSame('SUCCESS', $nmi['response_text']);
        self::assertSame(911, $nmi['amount']);
        self::assertSame('USD', $nmi['currency_code']);
    }

    /**
     * The row exists to be found from the gateway's id alone. Without one it could never be
     * found, so writing it would hide the failure until a notification arrived.
     */
    public function testATransactionWithNoIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->recorder->record($this->payment(), $this->response(id: null), NmiTransactionInterface::TYPE_SALE);
    }

    #[DataProvider('operationsItDoesNotKnow')]
    public function testAnOperationOutsideTheKnownSetIsRefused(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->recorder->record($this->payment(), $this->response(), $type);
    }

    /** @return iterable<string, array{string}> */
    public static function operationsItDoesNotKnow(): iterable
    {
        yield 'the response payment-method type' => ['cc'];
        yield 'a payment-request action' => ['notify'];
        yield 'empty' => [''];
    }

    /** The gateway's currency wins; the payment's is only the fallback. */
    public function testTheCurrencyComesFromTheGateway(): void
    {
        $payment = $this->payment();
        $payment->setCurrencyCode('EUR');

        $transaction = $this->recorder->record($payment, $this->response(), NmiTransactionInterface::TYPE_SALE);

        self::assertSame('USD', $transaction->getCurrencyCode());
    }

    private function payment(): PaymentInterface
    {
        $payment = new Payment();
        $payment->setCurrencyCode('USD');
        $payment->setAmount(911);

        return $payment;
    }

    private function response(?string $id = '12513506464', string $amount = '9.11', ?string $authCode = '123456'): NmiResponse
    {
        $body = [
            'object' => 'transaction',
            'type' => 'cc',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ];

        if (null !== $id) {
            $body['id'] = $id;
        }
        if (null !== $authCode) {
            $body['auth_code'] = $authCode;
        }

        return NmiResponse::fromBody(json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
