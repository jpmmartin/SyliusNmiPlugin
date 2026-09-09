<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command\Console;

use JpmMartin\SyliusNmiPlugin\Repository\NmiReceivedEventRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bounds the record of received webhook deliveries.
 *
 * **The retention period is a correctness constraint, not a preference.** That record is what makes
 * a repeated delivery change state once, so pruning an event the gateway could still redeliver
 * reopens the hole it exists to close. The gateway retries for three days; the default here is
 * thirty, which is that window with an order of magnitude of margin and still short enough to keep
 * the table from being a permanent archive of payloads carrying billing addresses and cardholder
 * emails.
 *
 * **Anything an operator has to keep is kept elsewhere.** Chargebacks and failed settlements
 * become their own durable notices precisely so that this table can be deleted without losing
 * them.
 *
 * Meant for a scheduler — daily is ample — and the README says so beside the number.
 */
#[AsCommand(
    name: 'jpm-martin:sylius-nmi:prune-received-events',
    description: 'Removes the record of webhook deliveries older than the retention period.',
)]
final class PruneReceivedEventsCommand extends Command
{
    /**
     * Three days of gateway retries with an order of magnitude of margin.
     *
     * Lowering it below four is unsafe rather than merely aggressive: a delivery the gateway is
     * still retrying would find no record of itself and be applied a second time.
     */
    public const DEFAULT_DAYS = 30;

    /** Below this, the gateway's own retry window is not covered. */
    private const MINIMUM_SAFE_DAYS = 4;

    public function __construct(
        private readonly NmiReceivedEventRepositoryInterface $receivedEventRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'How many days of received events to keep. The gateway retries for three, so anything under four risks applying a redelivered event twice.',
                (string) self::DEFAULT_DAYS,
            )
            ->addOption('force', null, InputOption::VALUE_NONE, 'Prune even when the period is shorter than the gateway\'s retry window.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = filter_var($input->getOption('days'), \FILTER_VALIDATE_INT);
        if (false === $days || $days < 1) {
            $io->error('The retention period must be a whole number of days, and at least one.');

            return Command::INVALID;
        }

        // Refused rather than warned about. A period inside the retry window does not merely keep
        // fewer rows; it makes a redelivered event look new, and the money moves twice.
        if ($days < self::MINIMUM_SAFE_DAYS && true !== $input->getOption('force')) {
            $io->error(sprintf(
                'A retention period of %d day(s) is shorter than the three days the gateway keeps retrying, so a redelivered event would be applied a second time. Use at least %d, or pass --force if you know why you are doing this.',
                $days,
                self::MINIMUM_SAFE_DAYS,
            ));

            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(sprintf('-%d days', $days));
        $removed = $this->receivedEventRepository->deleteReceivedBefore($before);

        $io->success(sprintf(
            'Removed %d received event(s) older than %s. Anything an operator still needs — chargebacks, failed settlements — lives in its own record and is untouched.',
            $removed,
            $before->format('Y-m-d H:i'),
        ));

        return Command::SUCCESS;
    }
}
