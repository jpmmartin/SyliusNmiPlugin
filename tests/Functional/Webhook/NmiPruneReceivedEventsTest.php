<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\Console\PruneReceivedEventsCommand;
use JpmMartin\SyliusNmiPlugin\Entity\NmiReceivedEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Bounding the record of received deliveries without weakening what it guarantees.
 *
 * **The period is a correctness constraint.** That record is the only thing making a repeated
 * delivery change state once, so pruning an event the gateway could still redeliver reopens the
 * hole it exists to close — which is why a period inside the retry window is refused rather than
 * warned about, and why the interesting assertion here is not that old rows go but that a recent
 * one survives and still stops a replay.
 */
final class NmiPruneReceivedEventsTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    private string $sale;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_prn_' . bin2hex(random_bytes(4));
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

    /**
     * *Pruning old events.* The old ones go, the recent one stays, and redelivering the recent one
     * still changes state no further — which is the half that matters.
     */
    public function testOnlyTheOldEventsGoAndARecentReplayIsStillStopped(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        // A recent delivery, made through the endpoint so it is a real row with a real event id.
        $this->deliver('transaction.refund.success', 'prune-recent');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($payment));

        $this->anEventReceived('prune-ancient', new \DateTimeImmutable('-90 days'));
        $this->anEventReceived('prune-old', new \DateTimeImmutable('-31 days'));

        self::assertSame(3, $this->storedEvents());

        $this->prune(['--days' => '30']);

        self::assertSame(1, $this->storedEvents(), 'Only what is older than the period goes.');

        // The half that matters: the surviving row still does its job.
        $this->deliver('transaction.refund.success', 'prune-recent');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->storedEvents(), 'A replay of a kept event writes nothing further.');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($payment), 'And changes state no further.');
    }

    /**
     * A period inside the gateway's own retry window is refused. It does not merely keep fewer
     * rows: a delivery still being retried would find no record of itself and be applied twice.
     */
    public function testAPeriodShorterThanTheRetryWindowIsRefusedAndDeletesNothing(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);
        $this->anEventReceived('prune-refused', new \DateTimeImmutable('-90 days'));

        $tester = $this->prune(['--days' => '2'], Command::INVALID);

        // Whitespace collapsed first: the console wraps the sentence, so asserting on the raw
        // display would be asserting on a terminal width.
        $said = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('shorter than the three days the gateway keeps retrying', $said);
        self::assertSame(1, $this->storedEvents(), 'Refused means nothing was deleted.');
    }

    /** Refused, not forbidden: an operator who knows why can still say so. */
    public function testTheRefusalCanBeOverriddenDeliberately(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);
        $this->anEventReceived('prune-forced', new \DateTimeImmutable('-90 days'));

        $this->prune(['--days' => '2', '--force' => true]);

        self::assertSame(0, $this->storedEvents());
    }

    /** The default is the one most stores will run, so it is pinned rather than assumed. */
    public function testTheDefaultPeriodComfortablyExceedsTheRetryWindow(): void
    {
        self::assertGreaterThan(3, PruneReceivedEventsCommand::DEFAULT_DAYS, 'The gateway retries for three days.');
        self::assertSame(30, PruneReceivedEventsCommand::DEFAULT_DAYS);
    }

    /** @param array<string, mixed> $input */
    private function prune(array $input = [], int $expected = Command::SUCCESS): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('jpm-martin:sylius-nmi:prune-received-events'));
        $tester->execute($input);

        self::assertSame($expected, $tester->getStatusCode());

        return $tester;
    }

    /** A row of a chosen age, written directly because the endpoint can only make recent ones. */
    private function anEventReceived(string $eventId, \DateTimeImmutable $receivedAt): void
    {
        $metadata = $this->manager->getClassMetadata(NmiReceivedEvent::class);
        $identifier = [];
        $generator = $metadata->idGenerator;
        if (null !== $generator && !$generator->isPostInsertGenerator()) {
            $identifier[$metadata->getSingleIdentifierColumnName()] = $generator->generateId($this->manager, null);
        }

        $this->manager->getConnection()->insert('jpm_martin_sylius_nmi_received_event', $identifier + [
            'event_id' => $eventId . '-' . $this->code,
            'event_type' => 'transaction.sale.success',
            'payment_method_code' => $this->code,
            'payload' => '{}',
            'received_at' => $receivedAt,
        ], [
            'event_id' => 'string',
            'event_type' => 'string',
            'payment_method_code' => 'string',
            'payload' => 'text',
            'received_at' => 'datetime_immutable',
        ]);
    }

    private function storedEvents(): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );
    }

    private function stateOf(PaymentInterface $payment): ?string
    {
        $this->manager->clear();

        return $this->manager->find(\Sylius\Component\Core\Model\Payment::class, $payment->getId())?->getState();
    }
}
