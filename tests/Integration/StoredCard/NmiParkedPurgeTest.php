<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\StoredCard;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * *The gateway stays unreachable through every attempt*, for both paths that queue a purge.
 *
 * A purge is not retried until the gateway answers. The transport it is routed to tries it again a
 * few times and then keeps it in its failure transport, where an operator lists it and sends it
 * again. That is what the documentation promises, and it rests on two things this plugin does not
 * own in full: its own routing of the purge to Sylius's `main` transport, and `main` having a
 * failure transport at all. So it is proven by running it — through a real worker, the kernel's
 * own retry and failure listeners, and the transports the test application configures — rather than
 * by reading configuration.
 *
 * How many times it is tried, and how long apart, is Sylius's to decide and is not asserted. The
 * waits are real: the in-memory transport reads the test application's clock, which follows a date
 * file — the one Sylius's own Behat calendar steps write — rather than any clock a test can freeze,
 * so the retry delays are paid in full, about seven seconds a round.
 */
final class NmiParkedPurgeTest extends KernelTestCase
{
    /** How often an idle worker looks again, in microseconds. */
    private const POLL = 100_000;

    /** About twenty seconds of polling: more than any sensible retry strategy needs, so a loop that never parks fails rather than hangs. */
    private const MOST_WORKER_TICKS = 200;

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

        $this->transport('main')->reset();
        $this->transport('main_failed')->reset();

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testAPurgeTheGatewayKeepsRefusingIsKeptForAnOperatorAndCanBeSentAgain(): void
    {
        $paymentMethod = $this->aPaymentMethod();
        $this->manager->flush();

        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(503));

        $this->eventBus()->dispatch(new PurgeStoredCard('vault-1111', (string) $paymentMethod->getCode()));

        self::assertCount(1, [...$this->transport('main')->get()], 'The purge was not queued on the transport it is routed to.');

        $this->consume('main');

        self::assertSame(0, self::stillPendingIn($this->transport('main')), 'The purge is still being retried.');
        self::assertGreaterThan(1, count($this->gateway->deletedVaultIds), 'The purge was not attempted again.');

        $parked = [...$this->transport('main_failed')->get()];
        self::assertCount(1, $parked, 'The purge was dropped instead of kept for an operator.');
        $message = $parked[0]->getMessage();
        self::assertInstanceOf(PurgeStoredCard::class, $message);
        self::assertSame('vault-1111', $message->vaultId);

        // What `messenger:failed:retry --transport main_failed` does, once the gateway answers.
        $this->gateway->willApprove();
        $attemptsBefore = count($this->gateway->deletedVaultIds);

        $this->consume('main_failed');

        self::assertSame(['vault-1111'], array_slice($this->gateway->deletedVaultIds, $attemptsBefore), 'Sending the purge again did not reach the gateway.');
        self::assertSame(0, self::stillPendingIn($this->transport('main_failed')), 'The purge sent again is still parked.');
        self::assertSame(0, self::stillPendingIn($this->transport('main')));
    }

    /**
     * *Sent again while the gateway is still unreachable*: the one step where a purge is lost.
     *
     * Parking resets the retry count, so the resent purge is tried again under the failure
     * transport's own strategy — and that transport has no failure transport of its own, so when
     * those attempts run out it is rejected and gone, with an error in the log. Asserted rather than
     * merely documented, so that the warning beside the resend command is revisited if Symfony or
     * Sylius ever starts keeping it.
     */
    public function testAPurgeSentAgainWhileTheGatewayIsStillDownIsDiscarded(): void
    {
        $paymentMethod = $this->aPaymentMethod();
        $this->manager->flush();

        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(503));

        $this->eventBus()->dispatch(new PurgeStoredCard('vault-2222', (string) $paymentMethod->getCode()));
        $this->consume('main');
        self::assertCount(1, [...$this->transport('main_failed')->get()], 'The purge was not parked in the first place.');

        $attemptsBefore = count($this->gateway->deletedVaultIds);
        $log = new TestHandler();
        $this->messengerLogger()->pushHandler($log);

        try {
            // The gateway is still down when the operator sends it again.
            $this->consume('main_failed');
        } finally {
            $this->messengerLogger()->popHandler();
        }

        self::assertGreaterThan($attemptsBefore, count($this->gateway->deletedVaultIds), 'The resent purge never reached the gateway.');
        self::assertSame(0, self::stillPendingIn($this->transport('main_failed')), 'The resent purge is still parked.');
        self::assertSame(0, self::stillPendingIn($this->transport('main')));
        self::assertSame([], $this->transport('main_failed')->getAcknowledged(), 'The resent purge was handled, not discarded.');

        $errors = array_filter(
            $log->getRecords(),
            static fn (LogRecord $record): bool => $record->level->isHigherThan(Level::Warning) && PurgeStoredCard::class === ($record->context['class'] ?? null),
        );
        self::assertNotSame([], $errors, 'Nothing was logged at error level or above when the purge was discarded.');
    }

    /**
     * Runs a worker on one transport, through the kernel's own event dispatcher so the retry and
     * failure listeners take part, until nothing is left to handle on it.
     */
    private function consume(string $transportName): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $ticks = 0;
        $stopWhenIdle = static function (WorkerRunningEvent $event) use (&$ticks, $transportName): void {
            if (++$ticks > self::MOST_WORKER_TICKS) {
                throw new \LogicException(sprintf('The worker on "%s" never ran out of work.', $transportName));
            }

            if ($event->isWorkerIdle() && 0 === self::stillPendingIn(self::transportNamed($transportName))) {
                $event->getWorker()->stop();
            }
        };

        $dispatcher->addListener(WorkerRunningEvent::class, $stopWhenIdle);

        try {
            $worker = new Worker([$transportName => $this->transport($transportName)], $this->routableBus(), $dispatcher);
            $worker->run(['sleep' => self::POLL]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stopWhenIdle);
        }
    }

    /**
     * Idle is not enough on its own: a message waiting out its retry delay is not available yet, so
     * the worker reads idle while the purge is still pending. Every envelope sent to the transport
     * ends acknowledged or rejected — a retry is a fresh envelope, and the one that failed is
     * rejected — so what is neither is still waiting, delayed or not.
     */
    private static function stillPendingIn(InMemoryTransport $transport): int
    {
        return count($transport->getSent()) - count($transport->getAcknowledged()) - count($transport->getRejected());
    }

    private static function transportNamed(string $name): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.' . $name);

        return $transport;
    }

    private function transport(string $name): InMemoryTransport
    {
        return self::transportNamed($name);
    }

    /** The logger Symfony's retry listener writes to when it gives up on a message. */
    private function messengerLogger(): Logger
    {
        /** @var Logger $logger */
        $logger = self::getContainer()->get('monolog.logger.messenger');

        return $logger;
    }

    private function eventBus(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('sylius.event_bus');

        return $bus;
    }

    /** The bus a real worker dispatches on: it reads which bus a message was sent from. */
    private function routableBus(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('messenger.routable_message_bus');

        return $bus;
    }

    private function aPaymentMethod(): PaymentMethodInterface
    {
        $container = self::getContainer();

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-account',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-account',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
        ]);
        $this->manager->persist($gatewayConfig);

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $container->get('sylius.factory.payment_method')->createNew();
        $paymentMethod->setCode('nmi_' . bin2hex(random_bytes(4)));
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $this->manager->persist($paymentMethod);

        return $paymentMethod;
    }
}
