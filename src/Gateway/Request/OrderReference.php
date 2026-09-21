<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * What the gateway is told an order is: its number, which is what the merchant knows it by and
 * types into the portal's search.
 *
 * Not the order's token. The gateway keeps fewer than fifty characters here and a Sylius order
 * token is sixty-four, so sending the token made every real checkout fail with a validation error
 * while the short tokens of seeded test orders sailed through. A number is nine characters; the cut
 * is there for a store that numbers its orders some other way.
 *
 * One place, because a charge the shopper makes and a charge the store makes later have to name
 * the same order the same way — that is how the merchant finds both in the portal.
 *
 * @internal
 */
final class OrderReference
{
    /** The most the gateway keeps: its rule is "fewer than 50 characters". */
    private const LENGTH = 49;

    public static function of(?OrderInterface $order): ?string
    {
        $number = $order?->getNumber();

        return null !== $number && '' !== $number ? substr($number, 0, self::LENGTH) : null;
    }
}
