<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CommandHandler\NotifyPaymentHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookSignature;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the gateway did to a payment somewhere else, arriving at the endpoint.
 *
 * **Every fixture here is built rather than found.** Continuous integration migrates an empty
 * database and loads no fixtures, so a test that took whichever payment happened to exist would be
 * green on a developer's machine and red — or worse, green for the wrong reason — on CI.
 */
final class NmiTransactionEventTest extends WebTestCase
{
    private const SIGNING_KEY = 'a-signing-key-for-the-suite';

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

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_evt_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);
    }

    protected function tearDown(): void
    {
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );

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

    /** An event naming a transaction this store does not know creates nothing and still succeeds. */
    public function testAnEventForATransactionThisStoreDoesNotKnowCreatesNothing(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $before = $this->totalPaymentRequests();
        $this->deliver('transaction.refund.success', 'ref-unknown', [], '99999999999');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'It must succeed, or the gateway redelivers for three days.');
        self::assertSame($before, $this->totalPaymentRequests());
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

    private function deliver(string $eventType, string $eventId, array $extraBody = [], ?string $transactionId = null): void
    {
        $transactionId ??= $this->sale;

        $body = json_encode([
            'event_id' => $eventId . '-' . $this->code,
            'event_type' => $eventType,
            'event_body' => ['transaction_id' => $transactionId] + $extraBody,
        ], \JSON_THROW_ON_ERROR);

        $nonce = '1788895697';
        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_' . str_replace('-', '_', strtoupper(NmiWebhookSignature::HEADER)) => sprintf(
                't=%s,s=%s',
                $nonce,
                hash_hmac('sha256', $nonce . '.' . $body, self::SIGNING_KEY),
            ),
        ], $body);
    }

    private function stateOf(PaymentInterface $payment): ?string
    {
        return $this->refreshed($payment)->getState();
    }

    private function refreshed(PaymentInterface $payment): PaymentInterface
    {
        $this->manager->clear();

        /** @var PaymentInterface $fresh */
        $fresh = $this->manager->find(Payment::class, $payment->getId());

        return $fresh;
    }

    /**
     * What the notify handler said it did.
     *
     * **Read through the ORM, not off the row.** `sylius_payment_request.response_data` is
     * encrypted at rest by the same mechanism that protects the gateway credentials, so a raw
     * `SELECT` returns ciphertext — which a test would happily compare against and always find
     * different.
     */
    private function lastNotifyResult(PaymentInterface $payment): ?string
    {
        $requests = $this->manager->createQuery(
            'SELECT r FROM Sylius\\Component\\Payment\\Model\\PaymentRequest r WHERE IDENTITY(r.payment) = :payment AND r.action = :action ORDER BY r.createdAt ASC',
        )->setParameters(['payment' => $payment->getId(), 'action' => PaymentRequestInterface::ACTION_NOTIFY])->getResult();

        $last = end($requests);
        if (false === $last) {
            return null;
        }

        $result = $last->getResponseData()['result'] ?? null;

        return is_string($result) ? $result : null;
    }

    private function notifyRequestCount(PaymentInterface $payment): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sylius_payment_request WHERE payment_id = :id AND action = :action',
            ['id' => $payment->getId(), 'action' => PaymentRequestInterface::ACTION_NOTIFY],
        );
    }

    private function totalPaymentRequests(): int
    {
        return (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM sylius_payment_request');
    }

    private function recorder(): NmiTransactionRecorderInterface
    {
        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');

        return $recorder;
    }

    private function approved(string $transactionId): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'type' => 'cc',
            'amount' => '12.99',
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR));
    }

    /** A payment in a given state, with the sale that produced it recorded against it. */
    private function aPaymentIn(string $state): PaymentInterface
    {
        $currency = $this->manager->getRepository(Currency::class)->findOneBy(['code' => 'USD']) ?? new Currency();
        $currency->setCode('USD');
        $this->manager->persist($currency);

        $locale = $this->manager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']) ?? new Locale();
        $locale->setCode('en_US');
        $this->manager->persist($locale);

        $channel = new Channel();
        $channel->setCode($this->code);
        $channel->setName('NMI event channel');
        $channel->setHostname($this->code . '.localhost');
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->addCurrency($currency);
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $this->manager->persist($channel);

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-evt',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-evt',
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            NmiGatewayFactory::CONFIG_WEBHOOK_SIGNING_KEY => self::SIGNING_KEY,
        ]);
        $gatewayConfig->setUsePayum(false);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode($this->code);
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);
        $paymentMethod->addChannel($channel);
        $this->manager->persist($gatewayConfig);
        $this->manager->persist($paymentMethod);

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $this->manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setMethod($paymentMethod);
        $payment->setCurrencyCode('USD');
        $payment->setAmount(1299);
        $payment->setState($state);
        $this->manager->persist($payment);
        $this->manager->flush();

        // The sale the event will name. Without it nothing resolves the payment, which is the
        // whole mechanism this test is about.
        $this->recorder()->record($payment, $this->approved($this->sale), NmiTransactionInterface::TYPE_SALE);
        $this->manager->flush();

        return $payment;
    }
}
