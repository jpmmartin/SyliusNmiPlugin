<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Provider;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Provider\NmiCardSavingCustomerProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Customer\Model\CustomerInterface as BaseCustomerInterface;
use Sylius\Component\Payment\Model\Payment as BarePayment;

/**
 * Who may keep a card on file. Three conditions, and every test here removes exactly one of them.
 *
 * The last case is the one that cannot be reached through the storefront and matters most: a
 * signed-in shopper reaching a payment that is not theirs.
 */
final class NmiCardSavingCustomerProviderTest extends TestCase
{
    public function testTheCustomerPayingForTheirOwnOrderMaySave(): void
    {
        $customer = self::customer(1);

        self::assertSame(
            $customer,
            self::provider($customer)->forPayment(self::payment($customer), self::configuration()),
        );
    }

    public function testNobodyMaySaveWhileTheSettingIsOff(): void
    {
        $customer = self::customer(1);

        self::assertNull(
            self::provider($customer)->forPayment(self::payment($customer), self::configuration(storeCards: false)),
        );
    }

    /** A guest. The order may well carry a customer; nobody is signed in as one. */
    public function testAGuestMayNotSave(): void
    {
        self::assertNull(
            self::provider(null)->forPayment(self::payment(self::customer(1)), self::configuration()),
        );
    }

    public function testAnOrderWithNoCustomerYieldsNobody(): void
    {
        self::assertNull(
            self::provider(self::customer(1))->forPayment(self::payment(null), self::configuration()),
        );
    }

    /**
     * The one that would be a security defect. Signed in, the setting on, and the payment belongs
     * to somebody else — a card filed against the wrong person is worse than no card.
     */
    public function testSomebodyElsesOrderYieldsNobody(): void
    {
        self::assertNull(
            self::provider(self::customer(1))->forPayment(self::payment(self::customer(2)), self::configuration()),
        );
    }

    /** A store that replaced the payment model with one that has no order. */
    public function testAPaymentWithNoOrderBehindItYieldsNobody(): void
    {
        self::assertNull(
            self::provider(self::customer(1))->forPayment(new BarePayment(), self::configuration()),
        );
    }

    private static function provider(?CustomerInterface $signedIn): NmiCardSavingCustomerProvider
    {
        return new NmiCardSavingCustomerProvider(new class($signedIn) implements CustomerContextInterface {
            public function __construct(private readonly ?CustomerInterface $customer)
            {
            }

            public function getCustomer(): ?BaseCustomerInterface
            {
                return $this->customer;
            }
        });
    }

    private static function payment(?CustomerInterface $customer): Payment
    {
        $order = new Order();
        if (null !== $customer) {
            $order->setCustomer($customer);
        }

        $payment = new Payment();
        $payment->setOrder($order);

        return $payment;
    }

    /**
     * The identifier is what the provider compares, so a fixture without one would pass for the
     * wrong reason. Doctrine assigns it and the model exposes no setter, hence the reflection.
     */
    private static function customer(int $id): CustomerInterface
    {
        $customer = new Customer();

        $property = new \ReflectionProperty(Customer::class, 'id');
        $property->setValue($customer, $id);

        return $customer;
    }

    private static function configuration(bool $storeCards = true): NmiGatewayConfiguration
    {
        return new NmiGatewayConfiguration(
            tokenizationKey: 'tok-public-0123',
            securityKey: 'sec-private-4567',
            environment: NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            useAuthorize: false,
            apiBaseUrl: 'https://sandbox.nmi.com',
            storeCards: $storeCards,
        );
    }
}
