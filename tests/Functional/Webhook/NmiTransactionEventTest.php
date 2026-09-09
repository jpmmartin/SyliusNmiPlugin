<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CommandHandler\NotifyPaymentHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\RecordingLogger;

/**
 * What the gateway did to a payment somewhere else, arriving at the endpoint.
 *
 * **Every fixture here is built rather than found.** Continuous integration migrates an empty
 * database and loads no fixtures, so a test that took whichever payment happened to exist would be
 * green on a developer's machine and red — or worse, green for the wrong reason — on CI.
 */
final class NmiTransactionEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    /**
     * **A different transaction identifier every run, and that is not cosmetic.** The recorder
     * treats the same identifier and operation as one transaction and hands back the existing row
     * rather than writing a second — correct in production, and lethal in a test that reuses a
     * constant: the fixture silently attaches to the previous run's payment, and the endpoint then
     * resolves *that* one. It looks like the code found the wrong payment. It found the right one.
     */
    private string $sale;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_evt_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);

        $this->logger = new RecordingLogger();
        self::getContainer()->set('logger', $this->logger);
    }

    protected function tearDown(): void
    {
        foreach (['jpm_martin_sylius_nmi_gateway_notice', 'jpm_martin_sylius_nmi_received_event'] as $table) {
            $this->manager->getConnection()->executeStatement(
                sprintf('DELETE FROM %s WHERE payment_method_code = :code', $table),
                ['code' => $this->code],
            );
        }

        parent::tearDown();
    }

    /** *A refund performed outside the store.* */
    public function testARefundPerformedInThePortalMovesThePaymentToRefunded(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliver('transaction.refund.success', 'ref-1');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($payment));
    }

    /** *A void performed outside the store.* */
    public function testAVoidPerformedInThePortalMovesThePaymentToCancelled(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_AUTHORIZED);

        $this->deliver('transaction.void.success', 'void-1');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->stateOf($payment));
    }

    /**
     * *An event describing the state already held.* Out-of-order delivery, a replay, and an
     * operator who did the same thing in both places are all this same case, and none of them is
     * an error.
     */
    public function testAnEventForAStateThePaymentAlreadyHoldsChangesNothingAndIsNotAnError(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_REFUNDED);

        $this->deliver('transaction.refund.success', 'ref-already');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($payment));
        self::assertSame('already_applied', $this->lastNotifyResult($payment));
    }

    /**
     * The gateway's own retry. The guard that makes this true is the unique index, and this is
     * where "changes state exactly once" stops being a claim about a row count and becomes one
     * about the payment — which is what task 2.3 could not assert when it was written.
     */
    public function testReplayingASuccessEventAppliesItOnlyOnce(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliver('transaction.refund.success', 'ref-twice');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($payment));
        $first = $this->notifyRequestCount($payment);

        $this->deliver('transaction.refund.success', 'ref-twice');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($payment));
        self::assertSame($first, $this->notifyRequestCount($payment), 'The repeat must not reach the payment at all: the ledger stops it before anything is announced.');
    }

    /** *A failure event.* The reason is recorded and no state is invented. */
    public function testAFailureEventRecordsItsReasonAndChangesNoState(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliver('transaction.refund.failure', 'ref-failed', ['action' => ['response_text' => 'REFUND NOT ALLOWED']]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->stateOf($payment), 'A failed operation moved no money, so it must move no state.');

        $refusal = $this->refreshed($payment)->getDetails()['nmi_refusal'] ?? null;
        self::assertIsArray($refusal);
        self::assertSame(NotifyPaymentHandler::FAILURE_MESSAGE_KEY, $refusal['message_key']);
        self::assertSame('REFUND NOT ALLOWED', $refusal['detail'], "The gateway's own sentence is the only description some failures have.");
    }

    /**
     * *An event for a transaction this store does not know.* Nothing is created — not a payment
     * request, not a notice — and it still succeeds, because a gateway account shared with another
     * store produces these continuously and twenty retries of each would be a flood.
     */
    public function testAnEventForATransactionThisStoreDoesNotKnowCreatesNothing(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $before = $this->totalPaymentRequests();
        $this->deliver('transaction.refund.success', 'ref-unknown', [], '99999999999');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'It must succeed, or the gateway redelivers for three days.');
        self::assertSame($before, $this->totalPaymentRequests());
        self::assertSame(0, $this->noticeCount(), 'And no notice either: the default is silence.');
    }

    /** *The notice enabled.* */
    public function testWithTheNoticeOnAnUnknownTransactionIsRaisedForTheOperator(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED, notifyUnknownTransactions: true);

        $this->deliver('transaction.refund.success', 'ref-unknown-on', [], '99999999999');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->noticeCount());
    }

    /** *The notice left at its default.* Still accepted, still logged, and nothing raised. */
    public function testWithTheNoticeAtItsDefaultNothingIsRaisedButItIsStillLogged(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliver('transaction.refund.success', 'ref-unknown-off', [], '99999999999');

        self::assertSame(0, $this->noticeCount());
        self::assertTrue(
            $this->logger->hasRecordContaining('does not know'),
            'A store that turned the notice off still gets the log line.',
        );
    }

    private function noticeCount(): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM jpm_martin_sylius_nmi_gateway_notice WHERE payment_method_code = :code',
            ['code' => $this->code],
        );
    }

    /**
     * A refund event may name the refund's own identifier rather than the sale's. The store
     * records the original as the parent, so both resolve — and this pins that, because searching
     * one column would resolve half the events and silently disown the rest.
     */
    public function testAnEventNamingTheRefundsOwnIdentifierStillResolves(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);
        $this->recorder()->record($payment, $this->approved($this->sale . '9'), NmiTransactionInterface::TYPE_REFUND, $this->sale);
        $this->manager->flush();

        $this->deliver('transaction.refund.success', 'ref-by-child', [], $this->sale . '9');

        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($payment));
    }
}
