<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Double\QueuedWorkCollector;
use Webmozart\Assert\Assert;

/**
 * What happens at the gateway once the operator has cancelled the order. Every other step — the
 * order page, cancelling, the order's and payment's states — is Sylius's own.
 *
 * The page queues its work and returns; a worker does the rest. This step is that worker: it
 * handles what the page sent to `main`, in this kernel, and then reads what the gateway was asked.
 */
final class CancellingNmiAuthorizedOrdersContext implements Context
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly NmiClientInterface $gateway,
        private readonly NmiTransactionRepositoryInterface $transactions,
        private readonly ObjectManager $manager,
    ) {
    }

    /**
     * @Then NMI should have voided the authorization :authorization once the queued work has run
     */
    public function nmiShouldHaveVoidedTheAuthorizationOnceTheQueuedWorkHasRun(string $authorization): void
    {
        // A worker reads what the page saved, not what the scenario's own steps still hold.
        $this->manager->clear();

        foreach (QueuedWorkCollector::takeAll() as $envelope) {
            $this->bus->dispatch($envelope->with(new ReceivedStamp('main')));
        }

        Assert::isInstanceOf($this->gateway, FakeNmiClient::class);
        Assert::same($this->gateway->voidedTransactionIds, [$authorization], 'NMI did not receive exactly one void of the authorisation.');
        Assert::notNull(
            $this->transactions->findOneByTransactionIdAndType($authorization, NmiTransactionInterface::TYPE_VOID),
            'The void is not recorded.',
        );
    }
}
