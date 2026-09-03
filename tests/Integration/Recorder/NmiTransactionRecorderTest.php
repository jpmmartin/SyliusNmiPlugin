<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recorder;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The point of the table is that a payment can be found from a gateway transaction id and
 * nothing else — that is what a notification will one day arrive with. These tests run against
 * the real database, because a unique index and a foreign key are not things a double has.
 *
 * Everything happens inside a transaction that is rolled back, so the database is left as found.
 */
final class NmiTransactionRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    private NmiTransactionRecorderInterface $recorder;

    private NmiTransactionRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $this->recorder = $recorder;

        /** @var NmiTransactionRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');
        $this->repository = $repository;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /**
     * The whole lifecycle against one payment, using the shapes the gateway really returns:
     * an authorisation, its capture under the same id, and a refund under a new one.
     */
    public function testEveryOperationLeavesOneRowResolvableByIdAlone(): void
    {
        $payment = $this->persistedPayment(2111);

        $this->recorder->record($payment, $this->transaction('12513542107', '21.11'), NmiTransactionInterface::TYPE_AUTH);
        $this->recorder->record($payment, $this->transaction('12513542107', '21.11'), NmiTransactionInterface::TYPE_CAPTURE);
        $this->recorder->record(
            $payment,
            $this->transaction('12513542390', '-10.00', authCode: null),
            NmiTransactionInterface::TYPE_REFUND,
            parentTransactionId: '12513542107',
        );
        $this->manager->flush();

        // An authorisation and its capture share one id, so the id alone finds both.
        $onTheAuthorisation = $this->repository->findByTransactionId('12513542107');
        self::assertCount(2, $onTheAuthorisation);
        self::assertEqualsCanonicalizing(
            [NmiTransactionInterface::TYPE_AUTH, NmiTransactionInterface::TYPE_CAPTURE],
            array_map(static fn (NmiTransactionInterface $t): ?string => $t->getType(), $onTheAuthorisation),
        );

        foreach ($onTheAuthorisation as $transaction) {
            self::assertSame($payment->getId(), $transaction->getPayment()?->getId());
        }

        // The refund is a transaction of its own and resolves to the same payment.
        $refunds = $this->repository->findByTransactionId('12513542390');
        self::assertCount(1, $refunds);
        self::assertSame($payment->getId(), $refunds[0]->getPayment()?->getId());
        self::assertSame(-1000, $refunds[0]->getAmount());
        self::assertSame('12513542107', $refunds[0]->getParentTransactionId());
    }

    /** Two payments must never resolve to each other. */
    public function testAnIdResolvesToItsOwnPaymentAndNoOther(): void
    {
        $first = $this->persistedPayment(500);
        $second = $this->persistedPayment(700);

        $this->recorder->record($first, $this->transaction('99000000001', '5.00'), NmiTransactionInterface::TYPE_SALE);
        $this->recorder->record($second, $this->transaction('99000000002', '7.00'), NmiTransactionInterface::TYPE_SALE);
        $this->manager->flush();

        self::assertSame($first->getId(), $this->repository->findByTransactionId('99000000001')[0]->getPayment()?->getId());
        self::assertSame($second->getId(), $this->repository->findByTransactionId('99000000002')[0]->getPayment()?->getId());
    }

    /** The gateway keeps this balance per transaction and will not report it, so the store counts. */
    public function testWhatHasBeenRefundedAgainstATransactionIsCounted(): void
    {
        $payment = $this->persistedPayment(600);

        $this->recorder->record($payment, $this->transaction('99000000003', '6.00'), NmiTransactionInterface::TYPE_SALE);
        self::assertSame(0, $this->repository->sumRefundedAgainst('99000000003'));

        foreach ([['99000000004', '-2.00'], ['99000000005', '-2.00']] as [$id, $amount]) {
            $this->recorder->record(
                $payment,
                $this->transaction($id, $amount, authCode: null),
                NmiTransactionInterface::TYPE_REFUND,
                parentTransactionId: '99000000003',
            );
        }
        $this->manager->flush();

        self::assertSame(-400, $this->repository->sumRefundedAgainst('99000000003'));
    }

    /** A replayed gateway answer must leave one row, not trip the unique index. */
    public function testRecordingTheSameOperationTwiceLeavesOneRow(): void
    {
        $payment = $this->persistedPayment(100);

        $this->recorder->record($payment, $this->transaction('99000000006', '1.00'), NmiTransactionInterface::TYPE_SALE);
        $this->manager->flush();
        $this->recorder->record($payment, $this->transaction('99000000006', '1.00'), NmiTransactionInterface::TYPE_SALE);
        $this->manager->flush();

        self::assertCount(1, $this->repository->findByTransactionId('99000000006'));
    }

    public function testTheAdminCopyIsOnThePaymentItself(): void
    {
        $payment = $this->persistedPayment(911);

        $this->recorder->record($payment, $this->transaction('99000000007', '9.11'), NmiTransactionInterface::TYPE_SALE);
        $this->manager->flush();
        $this->manager->refresh($payment);

        self::assertSame('99000000007', $payment->getDetails()[NmiTransactionRecorder::DETAILS_KEY]['transaction_id']);
    }

    /**
     * A payment cannot exist without an order in this schema, so one is made for it. Nothing
     * about the order matters here beyond being persistable.
     */
    private function persistedPayment(int $amount): PaymentInterface
    {
        $order = new Order();
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');

        $payment = new Payment();
        $payment->setCurrencyCode('USD');
        $payment->setAmount($amount);
        $payment->setOrder($order);

        $this->manager->persist($order);
        $this->manager->persist($payment);
        $this->manager->flush();

        return $payment;
    }

    private function transaction(string $id, string $amount, ?string $authCode = '123456'): NmiResponse
    {
        $body = [
            'object' => 'transaction',
            'id' => $id,
            'type' => 'cc',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ];

        if (null !== $authCode) {
            $body['auth_code'] = $authCode;
        }

        return NmiResponse::fromBody(json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
