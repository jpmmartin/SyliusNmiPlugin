<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes down that a delivery was accepted, and answers whether this store had seen it before.
 *
 * **The insert is the check.** A lookup followed by an insert is passed by two deliveries arriving
 * at the same instant — both find nothing, both insert, and the side effect runs twice. So the row
 * is inserted and the unique-constraint violation *is* the answer. That is the only version of this
 * that a concurrent redelivery cannot defeat, and the gateway redelivers for three days.
 *
 * **It writes through the connection rather than the ORM, deliberately.** A violation on
 * `EntityManager::flush()` closes the entity manager, and everything the rest of the request needs
 * to do — resolving a payment, applying a transition — would then fail for a reason that has
 * nothing to do with what went wrong. A duplicate delivery is the ordinary case here, not an
 * exceptional one, so it must not poison the request that discovers it.
 *
 * The table and column names still come from the mapping, so a store that replaced the model class
 * through the resource configuration is written to correctly rather than to a name hardcoded here.
 *
 * @internal
 */
final class NmiReceivedEventLedger implements NmiReceivedEventLedgerInterface
{
    /** @param class-string $eventClass */
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly string $eventClass,
    ) {
    }

    public function accept(NmiWebhookEnvelope $envelope, string $payload, string $paymentMethodCode): bool
    {
        $metadata = $this->manager->getClassMetadata($this->eventClass);

        $row = [
            $metadata->getColumnName('eventId') => $envelope->eventId,
            $metadata->getColumnName('eventType') => $envelope->eventType,
            $metadata->getColumnName('paymentMethodCode') => $paymentMethodCode,
            $metadata->getColumnName('payload') => $payload,
            $metadata->getColumnName('receivedAt') => new \DateTimeImmutable(),
        ];

        $types = [
            $metadata->getColumnName('eventId') => 'string',
            $metadata->getColumnName('eventType') => 'string',
            $metadata->getColumnName('paymentMethodCode') => 'string',
            $metadata->getColumnName('payload') => 'text',
            $metadata->getColumnName('receivedAt') => 'datetime_immutable',
        ];

        // **The identifier is the price of writing through the connection.** The ORM would have
        // supplied it; DBAL will not. Asking the mapping's own generator is what keeps this
        // portable: on PostgreSQL it draws from the sequence before the insert, and on MySQL it is
        // a post-insert generator with nothing to supply, so the auto-increment column is left out.
        $generator = $metadata->idGenerator;
        if (null !== $generator && !$generator->isPostInsertGenerator()) {
            $idColumn = $metadata->getSingleIdentifierColumnName();
            $row[$idColumn] = $generator->generateId($this->manager, null);
            $types[$idColumn] = 'integer';
        }

        try {
            $this->manager->getConnection()->insert($metadata->getTableName(), $row, $types);
        } catch (UniqueConstraintViolationException) {
            // Seen before. Not an error, and not something to tell the gateway about: it is
            // answered with success so it stops redelivering.
            return false;
        }

        return true;
    }
}
