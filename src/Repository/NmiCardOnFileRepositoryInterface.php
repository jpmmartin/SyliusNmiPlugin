<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @extends RepositoryInterface<NmiCardOnFileInterface>
 *
 * @internal
 */
interface NmiCardOnFileRepositoryInterface extends RepositoryInterface
{
    /**
     * The card a payment holds: not yet released, whatever the issuer has said about it.
     *
     * A closed card is returned rather than filtered out. A charge has to be able to say *why* it
     * was refused, and "closed" and "there is no card" are two different answers.
     */
    public function findHeldBy(PaymentInterface $payment): ?NmiCardOnFileInterface;

    /**
     * The same, with the row locked for writing until the surrounding transaction ends.
     *
     * Two charges of one payment — a worker and an operator, say — would otherwise both read the
     * payment as waiting and both reach the gateway. With the lock, the second waits for the first
     * to commit and then reads what the first did.
     */
    public function findHeldByForUpdate(PaymentInterface $payment): ?NmiCardOnFileInterface;

    /**
     * Every card on file of a payment method that has not been released, for the card updater,
     * which can only find a card by decrypting and comparing its vault reference.
     *
     * @return list<NmiCardOnFileInterface>
     */
    public function findHeldUnder(PaymentMethodInterface $paymentMethod): array;
}
