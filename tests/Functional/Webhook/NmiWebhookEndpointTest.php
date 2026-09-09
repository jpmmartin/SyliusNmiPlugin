<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookSignature;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\RecordingLogger;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * The public endpoint, exercised as the gateway exercises it.
 *
 * **This is the plugin's largest attack surface**, so what is asserted here is not only that a
 * genuine delivery works but that every way of failing writes nothing. A test that only proved the
 * happy path would be worth very little on a route anybody can POST to.
 *
 * Deliveries are signed here the way the gateway signs them, with the scheme confirmed against two
 * real captures before any of this was written: `t=<nonce>,s=<hex hmac over nonce.body>`.
 */
final class NmiWebhookEndpointTest extends WebTestCase
{
    private const SIGNING_KEY = 'a-signing-key-for-the-suite';

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_hook_' . bin2hex(random_bytes(4));

        // Swapped in the container, the way this suite swaps the gateway client. Some of what the
        // endpoint does has no other observable form than the line it writes.
        $this->logger = new RecordingLogger();
        self::getContainer()->set('logger', $this->logger);
    }

    protected function tearDown(): void
    {
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM sylius_payment_method WHERE code = :code',
            ['code' => $this->code],
        );

        parent::tearDown();
    }

    public function testACorrectlySignedDeliveryIsAcceptedAndRecordedOnce(): void
    {
        $this->aPaymentMethodWith(self::SIGNING_KEY);

        $body = $this->anEvent('11111111-1111-1111-1111-111111111111');
        $this->deliver($body, $this->signatureFor($body));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->recordedEvents());
    }

    /** @dataProvider deliveriesThatMustBeRefused */
    public function testARefusedDeliveryWritesNothing(?string $signature, string $why): void
    {
        $this->aPaymentMethodWith(self::SIGNING_KEY);

        $body = $this->anEvent('22222222-2222-2222-2222-222222222222');
        $this->deliver($body, $signature);

        self::assertSame(401, $this->client->getResponse()->getStatusCode(), $why);
        self::assertSame(0, $this->recordedEvents(), 'A refused delivery must write nothing: ' . $why);
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function deliveriesThatMustBeRefused(): iterable
    {
        yield 'no signature at all' => [null, 'An unsigned delivery is refused.'];
        yield 'a signature made with another key' => ['t=1788895697,s=' . str_repeat('a', 64), 'A wrongly signed delivery is refused.'];
        yield 'a header in a shape it cannot read' => ['not-a-signature-header', 'An unreadable signature header is refused.'];
    }

    /**
     * A method whose operator never pasted a signing key has nothing to verify against, so it
     * refuses — and refuses identically to a bad signature, so the endpoint cannot be used to find
     * out which methods take webhooks.
     */
    public function testAMethodWithNoSigningKeyRefusesEvenACorrectlyShapedDelivery(): void
    {
        $this->aPaymentMethodWith(null);

        $body = $this->anEvent('33333333-3333-3333-3333-333333333333');
        $this->deliver($body, $this->signatureFor($body));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->recordedEvents());
    }

    /** An unknown code answers the same way, for the same reason. */
    public function testAnUnknownPaymentMethodCodeIsRefusedTheSameWay(): void
    {
        $body = $this->anEvent('44444444-4444-4444-4444-444444444444');
        $this->deliver($body, $this->signatureFor($body));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Signed, so it is genuinely from the gateway, but not readable as an event. `400`, not `401`
     * and not a success: redelivering the same bytes cannot help, and a retry would be twenty
     * pointless deliveries.
     *
     * @dataProvider bodiesThatAreNotEvents
     */
    public function testASignedButMalformedBodyIsRefusedAndWritesNothing(string $body): void
    {
        $this->aPaymentMethodWith(self::SIGNING_KEY);

        $this->deliver($body, $this->signatureFor($body));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->recordedEvents());
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNotEvents(): iterable
    {
        yield 'not JSON' => ['this is not json at all'];
        yield 'JSON but not an object' => ['"a string"'];
        yield 'an object with no event id' => ['{"event_type":"transaction.sale.success","event_body":{}}'];
        yield 'an object with no event type' => ['{"event_id":"55555555-5555-5555-5555-555555555555","event_body":{}}'];
        yield 'an empty body' => [''];
    }

    /**
     * An event type this store does not act on is **not** a malformed one: it decodes, it is
     * recorded, and it is acknowledged. A gateway account shared with another store produces these
     * constantly.
     */
    public function testAnUnrecognisedEventTypeIsAcceptedAndRecorded(): void
    {
        $this->aPaymentMethodWith(self::SIGNING_KEY);

        $body = '{"event_id":"66666666-6666-6666-6666-666666666666","event_type":"recurring.plan.add","event_body":{}}';
        $this->deliver($body, $this->signatureFor($body));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->recordedEvents());
        self::assertTrue(
            $this->logger->hasRecordContaining('does not act on'),
            'It must be logged as well as recorded: an operator wondering why nothing happened needs to see that the delivery arrived.',
        );
    }

    /** The gateway's own retry, which must change state once and be answered with success twice. */
    public function testTheSameDeliveryRepeatedIsRecordedOnceAndAcceptedBothTimes(): void
    {
        $this->aPaymentMethodWith(self::SIGNING_KEY);

        $body = $this->anEvent('77777777-7777-7777-7777-777777777777');
        $signature = $this->signatureFor($body);

        $this->deliver($body, $signature);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->deliver($body, $signature);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'A repeat must not look like an error, or the gateway keeps redelivering.');

        self::assertSame(1, $this->recordedEvents());
    }

    /**
     * **The race a select-then-insert loses**, reproduced rather than argued about.
     *
     * A second connection opens its transaction before the first commits, so its snapshot does not
     * contain the row: a `SELECT` inside it finds nothing and would conclude the event is new.
     * Asserted here, so the test cannot pass for the wrong reason. The `INSERT` still fails,
     * because the unique index is enforced against committed data rather than against a snapshot —
     * which is exactly why the guarantee is a constraint and not a lookup.
     */
    public function testASecondConnectionThatCannotSeeTheRowStillCannotInsertIt(): void
    {
        $eventId = '88888888-8888-8888-8888-888888888888';

        $first = $this->manager->getConnection();
        $second = \Doctrine\DBAL\DriverManager::getConnection($first->getParams());
        $second->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);

        try {
            $second->beginTransaction();
            // Opens the snapshot before the other connection commits.
            $second->fetchOne('SELECT COUNT(*) FROM jpm_martin_sylius_nmi_received_event');

            $this->insertOn($first, $eventId);

            self::assertSame(
                0,
                (int) $second->fetchOne('SELECT COUNT(*) FROM jpm_martin_sylius_nmi_received_event WHERE event_id = :id', ['id' => $eventId]),
                'The second connection must not be able to see the row, or this proves nothing.',
            );

            $this->expectException(UniqueConstraintViolationException::class);
            $this->insertOn($second, $eventId);
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $second->close();

            $first->executeStatement('DELETE FROM jpm_martin_sylius_nmi_received_event WHERE event_id = :id', ['id' => $eventId]);
        }
    }

    /**
     * Writes a row on a given connection, drawing the identifier the way the mapping says to —
     * from the sequence before the insert where the platform has one, and not at all where the
     * column supplies its own.
     */
    private function insertOn(\Doctrine\DBAL\Connection $connection, string $eventId): void
    {
        $metadata = $this->manager->getClassMetadata(\JpmMartin\SyliusNmiPlugin\Entity\NmiReceivedEvent::class);
        $identifier = [];
        $generator = $metadata->idGenerator;
        if (null !== $generator && !$generator->isPostInsertGenerator()) {
            $identifier[$metadata->getSingleIdentifierColumnName()] = $generator->generateId($this->manager, null);
        }

        $connection->insert('jpm_martin_sylius_nmi_received_event', $identifier + [
            'event_id' => $eventId,
            'event_type' => 'transaction.sale.success',
            'payment_method_code' => $this->code,
            'payload' => '{}',
            'received_at' => new \DateTimeImmutable(),
        ], [
            'event_id' => 'string',
            'event_type' => 'string',
            'payment_method_code' => 'string',
            'payload' => 'text',
            'received_at' => 'datetime_immutable',
        ]);
    }

    private function deliver(string $body, ?string $signature): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $signature) {
            $server['HTTP_' . str_replace('-', '_', strtoupper(NmiWebhookSignature::HEADER))] = $signature;
        }

        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], $server, $body);
    }

    private function signatureFor(string $body, string $nonce = '1788895697'): string
    {
        return sprintf('t=%s,s=%s', $nonce, hash_hmac('sha256', $nonce . '.' . $body, self::SIGNING_KEY));
    }

    private function anEvent(string $eventId): string
    {
        return sprintf(
            '{"event_id":"%s","event_type":"transaction.sale.success","event_body":{"transaction_id":"12531781235"}}',
            $eventId,
        );
    }

    private function recordedEvents(): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );
    }

    private function aPaymentMethodWith(?string $signingKey): void
    {
        $config = [
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-hook',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-hook',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
        ];

        if (null !== $signingKey) {
            $config[NmiGatewayFactory::CONFIG_WEBHOOK_SIGNING_KEY] = $signingKey;
        }

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig($config);
        // What the admin form sets for any factory Payum does not know, and without which Sylius
        // stores the credentials unencrypted.
        $gatewayConfig->setUsePayum(false);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode($this->code);
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('NMI');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);

        $this->manager->persist($gatewayConfig);
        $this->manager->persist($paymentMethod);
        $this->manager->flush();
        $this->manager->clear();
    }
}
