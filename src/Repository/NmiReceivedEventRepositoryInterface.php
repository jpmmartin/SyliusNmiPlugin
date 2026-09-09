<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiReceivedEventInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @extends RepositoryInterface<NmiReceivedEventInterface>
 */
interface NmiReceivedEventRepositoryInterface extends RepositoryInterface
{
    /**
     * Removes every event received before the given moment and answers how many went.
     *
     * A bulk delete rather than a load-and-remove: the table this prunes exists because the
     * gateway may redeliver for three days, so on a busy store it is the one table that grows
     * without a business reason to keep it, and hydrating it to delete it would be the slowest
     * possible way to bound it.
     */
    public function deleteReceivedBefore(\DateTimeImmutable $moment): int;
}
