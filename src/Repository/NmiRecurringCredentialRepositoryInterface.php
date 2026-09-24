<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * The recurring credentials. A store finds one by the payment that opened it, keeps its id, and
 * later finds it again with `find()`.
 *
 * @extends RepositoryInterface<NmiRecurringCredentialInterface>
 */
interface NmiRecurringCredentialRepositoryInterface extends RepositoryInterface
{
    /**
     * The credential a payment's checkout kept, whatever has happened to it since — let go or closed
     * included, so that a caller can tell "there is none" from "it can no longer be charged".
     */
    public function findOpenedBy(PaymentInterface $payment): ?NmiRecurringCredentialInterface;

    /**
     * The same credential by id, with its row locked for writing until the surrounding transaction
     * ends. Two charges through one credential — a worker and an operator, say — would otherwise both
     * read their payment as waiting and both reach the gateway; with the lock the second waits for the
     * first to commit and then reads what the first did.
     */
    public function findForUpdate(int $id): ?NmiRecurringCredentialInterface;

    /**
     * A customer's credentials that have not been let go.
     *
     * @return list<NmiRecurringCredentialInterface>
     */
    public function findUnreleasedOf(CustomerInterface $customer): array;

    /**
     * A payment method's credentials that have not been let go, for the card updater, which can only
     * find one by decrypting and comparing its vault reference.
     *
     * @return list<NmiRecurringCredentialInterface>
     */
    public function findUnreleasedUnder(PaymentMethodInterface $paymentMethod): array;
}
