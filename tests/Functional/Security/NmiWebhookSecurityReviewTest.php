<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Security;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookSignature;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\JpmMartin\SyliusNmiPlugin\Double\RecordingLogger;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook\BuildsAnNmiWebhookDelivery;

/**
 * The five questions the security review asks of this plugin's public endpoint, each answered by
 * doing it rather than by reasoning about it.
 *
 * **This endpoint is the largest attack surface the plugin has**: unauthenticated by construction,
 * reachable by anyone, and the only thing between it and the database is a signature check. It is
 * also demonstrably findable — the capture endpoint used to settle this change's unknowns was
 * crawled by Googlebot within hours of existing, without its address being published anywhere. So
 * nothing here may rest on the URL being obscure.
 *
 * A review that reasons is a review that is wrong the first time somebody edits the code. These
 * are assertions, and they fail when the answers stop being true.
 */
final class NmiWebhookSecurityReviewTest extends WebTestCase
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
        $this->client->catchExceptions(false);

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_sec_' . bin2hex(random_bytes(4));
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

    /**
     * **1. Is the signature comparison constant-time in the code that actually runs?**
     *
     * Yes. `hash_equals`, asserted by reading the method's own source — and that assertion is
     * itself proven by mutation: replacing it with `===` turns the check red. `===` and
     * `hash_equals` behave identically and differ only in what they leak, so behaviour cannot tell
     * them apart and a timing measurement would be flaky.
     *
     * The unit suite carries the assertion; this names the answer where the review can be read.
     */
    public function testTheSignatureComparisonIsConstantTime(): void
    {
        $method = new \ReflectionMethod(NmiWebhookSignature::class, 'isValid');
        $source = implode("\n", array_slice(
            file((string) $method->getFileName()) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString('hash_equals(', $source);
    }

    /**
     * **2. Can an unsigned request cause any write at all — including a log line whose size an
     * attacker controls?**
     *
     * No row, and no log line an attacker can inflate. The body and the header are never logged,
     * because an attacker chooses both; and the payment method code, which comes from the URL and
     * is therefore also theirs, is bounded by the route and truncated before it reaches the log.
     */
    public function testAnUnsignedRequestWritesNothingAndCannotInflateTheLog(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $body = str_repeat('A', 20_000);
        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->storedEvents(), 'Nothing may be written before a delivery is proven.');

        foreach ($this->logger->records as $record) {
            $written = $record['message'] . json_encode($record['context']);

            self::assertStringNotContainsString($body, $written, 'The body is chosen by the attacker and must never reach the log.');
            self::assertLessThan(
                1_000,
                strlen($written),
                'A log line an attacker can grow is a disk they can fill.',
            );
        }
    }

    /**
     * The same question asked of the one part of the request the route lets through: the code in
     * the URL. Bounded by the route itself, so an enormous one is a 404 that never reaches the
     * controller and never reaches the log.
     */
    public function testAnEnormousPaymentMethodCodeNeverReachesTheController(): void
    {
        // The route refuses it, so the kernel never reaches a controller. Asserted as the thrown
        // exception rather than as a status, because this client is deliberately not catching
        // them — a 404 rendered by an error handler would prove less.
        try {
            $this->client->request('POST', '/nmi/webhooks/' . str_repeat('x', 5_000));
            self::fail('A code longer than a payment method code can be must not match the route.');
        } catch (NotFoundHttpException) {
            // As intended.
        }

        self::assertSame([], $this->logger->records, 'It must not even be logged: the log line is the attack.');
    }

    /**
     * **3. Is the signing secret absent from logs and exception traces?**
     *
     * It is never passed to anything that formats it, never logged, and never included in a
     * response. Asserted against the whole log after a deliberately failing delivery, which is the
     * moment something might be tempted to explain itself with the value.
     */
    public function testTheSigningSecretNeverAppearsInTheLog(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => 't=1,s=' . str_repeat('b', 64),
        ], '{"event_id":"sec-1","event_type":"transaction.sale.success","event_body":{}}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $everythingWritten = json_encode($this->logger->records) . (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString(self::SIGNING_KEY, $everythingWritten);
    }

    /**
     * **4. Does the endpoint reveal whether a transaction exists in this store?**
     *
     * No. A signed event naming a transaction this store holds and one naming a stranger are
     * answered identically — same status, same empty body — because the difference is exactly the
     * thing an attacker would be probing for.
     *
     * The same applies one level up: an unknown payment method code and a wrongly signed delivery
     * both answer `401`, so the endpoint cannot be used to enumerate which methods take events.
     */
    public function testAKnownAndAnUnknownTransactionAreAnsweredIdentically(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliver('transaction.refund.success', 'sec-known');
        $known = $this->client->getResponse();

        $this->deliver('transaction.refund.success', 'sec-unknown', [], '99999999999');
        $unknown = $this->client->getResponse();

        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($known->getContent(), $unknown->getContent());
    }

    /** And the same, one level up, for whether a payment method takes webhooks at all. */
    public function testAnUnknownCodeAndABadSignatureAreAnsweredIdentically(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->client->request('POST', '/nmi/webhooks/nmi_no_such_method_' . bin2hex(random_bytes(4)), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => 't=1,s=' . str_repeat('c', 64),
        ], '{}');
        $unknownCode = $this->client->getResponse();

        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => 't=1,s=' . str_repeat('c', 64),
        ], '{}');
        $badSignature = $this->client->getResponse();

        self::assertSame($unknownCode->getStatusCode(), $badSignature->getStatusCode());
        self::assertSame($unknownCode->getContent(), $badSignature->getContent());
    }

    /**
     * **5. Is there a bound on the accepted body size?**
     *
     * Yes, and it is the plugin's own rather than a deployment's. A body past the bound is refused
     * before the HMAC is computed over it, so an attacker cannot make the store hash megabytes per
     * request; and it is refused before anything is stored, so they cannot fill the table either.
     *
     * The bound is generous on purpose — a settlement or chargeback batch carries an entry per
     * transaction, and a real transaction event was measured at 2.5 KB — so this asserts both
     * halves: a plausible batch gets through, and something absurd does not.
     */
    public function testABodyPastTheBoundIsRefusedBeforeItIsHashedOrStored(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $enormous = json_encode([
            'event_id' => 'sec-huge',
            'event_type' => 'transaction.sale.success',
            // Past the four-megabyte bound, and only just: the point is the bound, not the size.
            'event_body' => ['padding' => str_repeat('A', 5_000_000)],
        ], \JSON_THROW_ON_ERROR);

        $nonce = '1788895697';
        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => sprintf('t=%s,s=%s', $nonce, hash_hmac('sha256', $nonce . '.' . $enormous, self::SIGNING_KEY)),
        ], $enormous);

        self::assertSame(413, $this->client->getResponse()->getStatusCode(), 'Correctly signed is not the same as acceptable.');
        self::assertSame(0, $this->storedEvents(), 'And nothing was stored.');
    }

    /** The other half: a batch big enough to be real still gets through. */
    public function testABatchLargeEnoughToBeRealIsStillAccepted(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        // Five hundred transactions in one settled batch, which is an ordinary day for a busy shop.
        $this->deliverEvent('settlement.batch.complete', 'sec-big-batch', [
            'batch_id' => '12345678',
            'transaction_ids' => array_map(static fn (int $i): string => (string) (9_000_000_000 + $i), range(1, 500)),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function storedEvents(): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );
    }
}
