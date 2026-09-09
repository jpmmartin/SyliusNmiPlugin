<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNoticeInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\RecordingLogger;

/**
 * Settlement, which arrives **per batch and never per transaction**.
 *
 * A completed batch names every transaction it carried, most of which belong to other stores on a
 * shared gateway account. A failed one names none at all — batch identifier, merchant, processor,
 * and nothing else — so it cannot be attributed to an order and is not.
 *
 * **None of this was observed against the sandbox, and it could not be.** A test-mode account
 * settles nothing: the portal's own report shows every transaction in a single *Not Settled*
 * bucket, and moving the batch cut-off forward produces no delivery. So these payloads are built
 * from the gateway's published samples, and the handler is written to treat any other shape as an
 * event it does not recognise rather than as a crash.
 */
final class NmiSettlementEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    private string $sale;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_stl_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);

        $this->logger = new RecordingLogger();
        self::getContainer()->set('logger', $this->logger);
    }

    protected function tearDown(): void
    {
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM jpm_martin_sylius_nmi_gateway_notice WHERE payment_method_code = :code',
            ['code' => $this->code],
        );
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );

        parent::tearDown();
    }

    /** *A batch settles.* */
    public function testABatchThatSettlesMarksTheTransactionsThisStoreKnows(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        self::assertNull($this->sale()->getSettledAt(), 'Nothing has settled yet.');

        $this->deliverSettlement('stl-1', [$this->sale, '9999999999', '8888888888']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->sale()->getSettledAt(), "The store's own transaction is marked.");
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState(), 'Settlement is a fact about a transaction, not a state of a payment.');
    }

    /**
     * The identifiers that match nothing are the normal case, not a failure: a batch names every
     * transaction the account settled that day, and a shared account has other stores in it.
     */
    public function testIdentifiersThisStoreDoesNotKnowAreIgnoredWithoutError(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliverSettlement('stl-strangers', ['1111111111', '2222222222']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->sale()->getSettledAt());
    }

    /**
     * A redelivered batch must not move the moment a transaction settled. The ledger stops the
     * repeat first, and the update refuses to touch an already-settled row second — belt and
     * braces, because the moment is what the reversal decision reads.
     */
    public function testARedeliveredBatchDoesNotMoveTheMomentItSettled(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliverSettlement('stl-twice', [$this->sale]);
        $first = $this->sale()->getSettledAt();
        self::assertNotNull($first);

        $this->deliverSettlement('stl-twice', [$this->sale]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertEquals($first, $this->sale()->getSettledAt());
    }

    /** A batch naming nothing is readable but empty. Noted, not treated as a fault. */
    public function testABatchNamingNoTransactionsIsAccepted(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliverSettlement('stl-empty', []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->logger->hasRecordContaining('named no transactions'));
    }

    /**
     * *A settlement fails.* It names no transaction, so nothing is attributed to any order — and
     * the batch identifier is kept, because it is the only handle an operator has when they ring
     * the gateway about it.
     */
    public function testAFailedSettlementIsRecordedAgainstTheAccountAndNoOrder(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliverEvent('settlement.batch.failure', 'stl-failed', [
            'batch_id' => '123456',
            'merchant' => ['id' => '926804', 'name' => 'Test Account'],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState(), 'It names no transaction, so it may touch no order.');
        self::assertNull($this->sale()->getSettledAt());

        $record = $this->logger->records[array_key_last($this->logger->records)] ?? null;
        self::assertIsArray($record);
        self::assertSame('error', $record['level'], 'Money that did not reach the processor is not an informational event.');
        self::assertSame('123456', $record['context']['batch_id'] ?? null);

        // The durable half. The log is for whoever watches logs; this is what the operator opens.
        $notice = $this->notice('123456');
        self::assertSame(NmiGatewayNoticeInterface::TYPE_SETTLEMENT_FAILURE, $notice->getType());
        self::assertSame($this->code, $notice->getPaymentMethodCode());
        self::assertNull($notice->getPayment(), 'It names no transaction, so it may name no order.');
    }

    /**
     * **The same batch reported twice leaves one notice.** The event ledger already stops a
     * redelivery, but not after the events have been pruned — and these outlive that, which is why
     * they exist at all.
     */
    public function testTheSameFailedBatchReportedTwiceLeavesOneNotice(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliverEvent('settlement.batch.failure', 'stl-dup-a', ['batch_id' => '778899']);
        $this->deliverEvent('settlement.batch.failure', 'stl-dup-b', ['batch_id' => '778899']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->noticeCount('778899'));
    }

    /** *Made visible to the operator.* Not a log line — the page an operator actually opens. */
    public function testAFailedSettlementIsVisibleOnTheAdminPage(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);
        $this->deliverEvent('settlement.batch.failure', 'stl-visible', ['batch_id' => '445566']);

        // Signed in as an administrator, the way the page is actually reached. Asserting against
        // the redirect to the login form would prove only that the firewall works.
        $container = self::getContainer();
        $admin = $container->get('sylius.factory.admin_user')->createNew();
        $admin->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $admin->setUsername('ada-' . bin2hex(random_bytes(4)));
        $admin->setPlainPassword('not-checked');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $this->manager->persist($admin);
        $this->manager->flush();

        $this->client->loginUser($admin, 'admin');
        $this->client->request('GET', '/admin/nmi-notices');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('445566', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Settlement failed', (string) $this->client->getResponse()->getContent());
    }

    private function notice(string $reference): NmiGatewayNoticeInterface
    {
        $this->manager->clear();

        $notice = self::getContainer()
            ->get('jpm_martin_sylius_nmi.repository.nmi_gateway_notice')
            ->findOneBy(['reference' => $reference])
        ;

        self::assertNotNull($notice);

        return $notice;
    }

    private function noticeCount(string $reference): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM jpm_martin_sylius_nmi_gateway_notice WHERE reference = :reference',
            ['reference' => $reference],
        );
    }

    /** @param list<string> $transactionIds */
    private function deliverSettlement(string $eventId, array $transactionIds): void
    {
        $this->deliverEvent('settlement.batch.complete', $eventId, [
            'batch_id' => '12345678',
            'count' => count($transactionIds),
            'transaction_ids' => $transactionIds,
        ]);
    }

    private function sale(): NmiTransactionInterface
    {
        $this->manager->clear();

        $transaction = self::getContainer()
            ->get('jpm_martin_sylius_nmi.repository.nmi_transaction')
            ->findOneByTransactionIdAndType($this->sale, NmiTransactionInterface::TYPE_SALE)
        ;

        self::assertNotNull($transaction);

        return $transaction;
    }
}
