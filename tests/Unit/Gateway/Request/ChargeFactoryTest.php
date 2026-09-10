<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Request;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ChargeFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;

/**
 * The charge as the plugin sends it when nobody decorated the factory: exactly what the handler
 * built before the factory existed, pinned so that a decorator has a known starting point.
 */
final class ChargeFactoryTest extends TestCase
{
    public function testATokenChargeCarriesThePaymentTheOrderAndTheAuthentication(): void
    {
        $charge = (new ChargeFactory())->forToken($this->aPayment('000000021'), 'tok-once-abc', [
            'ip_address' => ' 203.0.113.9 ',
            'cardholder_auth' => 'verified',
            'cavv' => 'AAABBJ',
            'eci' => '05',
            'three_ds_version' => '2.2.0',
            'directory_server_id' => 'ds-1',
        ], true);

        self::assertSame('tok-once-abc', $charge->paymentToken);
        self::assertSame(1299, $charge->amount);
        self::assertSame('USD', $charge->currencyCode);
        self::assertSame('000000021', $charge->orderId);
        self::assertSame('203.0.113.9', $charge->ipAddress, 'Trimmed, as the handler trimmed it.');
        self::assertNotNull($charge->threeDSecure);
        self::assertSame('AAABBJ', $charge->threeDSecure->cavv);
        self::assertSame('05', $charge->threeDSecure->eci);
        self::assertSame('2.2.0', $charge->threeDSecure->threeDsVersion);
        self::assertSame('ds-1', $charge->threeDSecure->directoryServerId);
        self::assertTrue($charge->storeCard);
        self::assertNull($charge->storedCard);
        self::assertNull($charge->orderDescription, 'Left for a decorator, on purpose.');
        self::assertNull($charge->billing, 'Left for a decorator, on purpose.');
        self::assertSame([], $charge->extra);
    }

    public function testAnOrderReferenceIsCutToWhatTheGatewayKeeps(): void
    {
        $charge = (new ChargeFactory())->forToken($this->aPayment(str_repeat('9', 64)), 'tok', [], false);

        self::assertSame(49, strlen((string) $charge->orderId));
    }

    public function testNoAuthenticationMeansNoAuthenticationObject(): void
    {
        $charge = (new ChargeFactory())->forToken($this->aPayment(null), 'tok', ['cavv' => '', 'ip_address' => null], false);

        self::assertNull($charge->orderId, 'An order without a number is sent without one.');
        self::assertNull($charge->ipAddress);
        self::assertNull($charge->threeDSecure, 'An empty authentication object is omitted, not sent.');
        self::assertFalse($charge->storeCard);
    }

    public function testAStoredCardChargeNamesTheVaultAndNoToken(): void
    {
        $card = $this->createStub(NmiStoredCardInterface::class);
        $card->method('getVaultId')->willReturn('1730549219');
        $card->method('getBillingId')->willReturn('349429273');
        $card->method('getVaultingTransactionId')->willReturn('12513506464');

        $charge = (new ChargeFactory())->forStoredCard($this->aPayment('000000022'), $card, ['cavv' => 'AAABBJ']);

        self::assertNull($charge->paymentToken);
        self::assertSame(1299, $charge->amount);
        self::assertSame('000000022', $charge->orderId);
        self::assertNotNull($charge->storedCard);
        self::assertSame('1730549219', $charge->storedCard->vaultId);
        self::assertSame('349429273', $charge->storedCard->billingId);
        self::assertSame('12513506464', $charge->storedCard->initialTransactionId);
        self::assertSame('AAABBJ', $charge->threeDSecure?->cavv);
        self::assertFalse($charge->storeCard);
    }

    /** A core payment always belongs to an order — its `getOrder()` insists — so "no number" is an order without one. */
    private function aPayment(?string $orderNumber): Payment
    {
        $payment = new Payment();
        $payment->setAmount(1299);
        $payment->setCurrencyCode('USD');

        $order = new Order();
        $order->setNumber($orderNumber);
        $order->addPayment($payment);

        return $payment;
    }
}
