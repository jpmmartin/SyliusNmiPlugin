<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund\Exception;

/**
 * Thrown out of the refund plugin's flow when the gateway did not give the money back.
 *
 * Its job is to reach the refund plugin's command bus, whose transaction then undoes the credit
 * memo, the refund units and the refund payment together: nothing half done survives. What the
 * operator is told travels separately, because the refund plugin shows a handler's failure as one
 * generic sentence.
 */
final class RefundNotPerformed extends \RuntimeException
{
    public static function refused(?string $reason): self
    {
        return new self(null === $reason || '' === $reason ? 'NMI refused the refund.' : sprintf('NMI refused the refund: %s', $reason));
    }

    public static function unreachable(): self
    {
        return new self('NMI could not be reached, so the refund was not performed.');
    }

    public static function nothingToRefund(): self
    {
        return new self('The payment has no recorded transaction to refund against.');
    }

    public static function exceedsWhatWasTaken(int $requested, int $remaining): self
    {
        return new self(sprintf('A refund of %d was requested but only %d of the transaction is left to give back.', $requested, $remaining));
    }
}
