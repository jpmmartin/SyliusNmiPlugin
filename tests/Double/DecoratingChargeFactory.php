<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Double;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ChargeFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * What a store's decorator of the charge factory looks like, registered in the test application
 * the way a store would register its own.
 *
 * Dormant unless a test says what to add, so that every other test still exercises the plugin's
 * default charge: with nothing to add, the inner factory's charge is returned untouched. A
 * description is a field the plugin models and leaves empty, so it is set by rebuilding the
 * charge; the rest goes through `with()`, which is the seam for fields the plugin does not model.
 */
final class DecoratingChargeFactory implements ChargeFactoryInterface
{
    public static ?string $orderDescription = null;

    /** @var array<string, mixed> */
    public static array $extra = [];

    public function __construct(private readonly ChargeFactoryInterface $inner)
    {
    }

    public static function reset(): void
    {
        self::$orderDescription = null;
        self::$extra = [];
    }

    public function forToken(PaymentInterface $payment, string $token, array $payload, bool $storeCard): Charge
    {
        return $this->decorate($this->inner->forToken($payment, $token, $payload, $storeCard));
    }

    public function forStoredCard(PaymentInterface $payment, NmiStoredCardInterface $card, array $payload): Charge
    {
        return $this->decorate($this->inner->forStoredCard($payment, $card, $payload));
    }

    private function decorate(Charge $charge): Charge
    {
        if (null === self::$orderDescription && [] === self::$extra) {
            return $charge;
        }

        $described = null === self::$orderDescription ? $charge : new Charge(
            paymentToken: $charge->paymentToken,
            amount: $charge->amount,
            currencyCode: $charge->currencyCode,
            orderId: $charge->orderId,
            orderDescription: self::$orderDescription,
            ipAddress: $charge->ipAddress,
            billing: $charge->billing,
            threeDSecure: $charge->threeDSecure,
            storeCard: $charge->storeCard,
            storedCard: $charge->storedCard,
            extra: $charge->extra,
        );

        return $described->with(self::$extra);
    }
}
