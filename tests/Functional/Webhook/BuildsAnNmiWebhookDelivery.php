<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookSignature;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * A store that can be delivered to, built rather than found.
 *
 * Shared by the tests that exercise the endpoint, because every one of them needs the same three
 * things: a payment method carrying a signing key, a payment, and a transaction recorded against
 * it for an event to name. Continuous integration migrates an empty database and loads no
 * fixtures, so none of that can be assumed to exist.
 */
trait BuildsAnNmiWebhookDelivery
{
    private const SIGNING_KEY = 'a-signing-key-for-the-suite';

    /**
     * A delivery whose body is exactly what is given, signed the way the gateway signs it.
     *
     * @param array<string, mixed> $eventBody
     */
    private function deliverEvent(string $eventType, string $eventId, array $eventBody): void
    {
        $body = json_encode([
            'event_id' => $eventId . '-' . $this->code,
            'event_type' => $eventType,
            'event_body' => $eventBody,
        ], \JSON_THROW_ON_ERROR);

        $nonce = '1788895697';
        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_' . str_replace('-', '_', strtoupper(NmiWebhookSignature::HEADER)) => sprintf(
                't=%s,s=%s',
                $nonce,
                hash_hmac('sha256', $nonce . '.' . $body, self::SIGNING_KEY),
            ),
        ], $body);
    }

    private function deliver(string $eventType, string $eventId, array $extraBody = [], ?string $transactionId = null): void
    {
        $transactionId ??= $this->sale;

        $body = json_encode([
            'event_id' => $eventId . '-' . $this->code,
            'event_type' => $eventType,
            'event_body' => ['transaction_id' => $transactionId] + $extraBody,
        ], \JSON_THROW_ON_ERROR);

        $nonce = '1788895697';
        $this->client->request('POST', '/nmi/webhooks/' . $this->code, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_' . str_replace('-', '_', strtoupper(NmiWebhookSignature::HEADER)) => sprintf(
                't=%s,s=%s',
                $nonce,
                hash_hmac('sha256', $nonce . '.' . $body, self::SIGNING_KEY),
            ),
        ], $body);
    }

    private function stateOf(PaymentInterface $payment): ?string
    {
        return $this->refreshed($payment)->getState();
    }

    private function refreshed(PaymentInterface $payment): PaymentInterface
    {
        $this->manager->clear();

        /** @var PaymentInterface $fresh */
        $fresh = $this->manager->find(Payment::class, $payment->getId());

        return $fresh;
    }

    /**
     * What the notify handler said it did.
     *
     * **Read through the ORM, not off the row.** `sylius_payment_request.response_data` is
     * encrypted at rest by the same mechanism that protects the gateway credentials, so a raw
     * `SELECT` returns ciphertext — which a test would happily compare against and always find
     * different.
     */
    private function lastNotifyResult(PaymentInterface $payment): ?string
    {
        $requests = $this->manager->createQuery(
            'SELECT r FROM Sylius\\Component\\Payment\\Model\\PaymentRequest r WHERE IDENTITY(r.payment) = :payment AND r.action = :action ORDER BY r.createdAt ASC',
        )->setParameters(['payment' => $payment->getId(), 'action' => PaymentRequestInterface::ACTION_NOTIFY])->getResult();

        $last = end($requests);
        if (false === $last) {
            return null;
        }

        $result = $last->getResponseData()['result'] ?? null;

        return is_string($result) ? $result : null;
    }

    private function notifyRequestCount(PaymentInterface $payment): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sylius_payment_request WHERE payment_id = :id AND action = :action',
            ['id' => $payment->getId(), 'action' => PaymentRequestInterface::ACTION_NOTIFY],
        );
    }

    private function totalPaymentRequests(): int
    {
        return (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM sylius_payment_request');
    }

    private function recorder(): NmiTransactionRecorderInterface
    {
        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');

        return $recorder;
    }

    private function approved(string $transactionId): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'type' => 'cc',
            'amount' => '12.99',
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR));
    }

    /** A payment in a given state, with the sale that produced it recorded against it. */
    private function aPaymentIn(string $state, bool $emailCardholder = false): PaymentInterface
    {
        $currency = $this->manager->getRepository(Currency::class)->findOneBy(['code' => 'USD']) ?? new Currency();
        $currency->setCode('USD');
        $this->manager->persist($currency);

        $locale = $this->manager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']) ?? new Locale();
        $locale->setCode('en_US');
        $this->manager->persist($locale);

        $channel = new Channel();
        $channel->setCode($this->code);
        $channel->setName('NMI event channel');
        $channel->setHostname($this->code . '.localhost');
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->addCurrency($currency);
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $this->manager->persist($channel);

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-evt',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-evt',
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            NmiGatewayFactory::CONFIG_WEBHOOK_SIGNING_KEY => self::SIGNING_KEY,
            NmiGatewayFactory::CONFIG_EMAIL_CARDHOLDER => $emailCardholder,
        ]);
        $gatewayConfig->setUsePayum(false);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode($this->code);
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);
        $paymentMethod->addChannel($channel);
        $this->manager->persist($gatewayConfig);
        $this->manager->persist($paymentMethod);

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $this->manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setMethod($paymentMethod);
        $payment->setCurrencyCode('USD');
        $payment->setAmount(1299);
        $payment->setState($state);
        $this->manager->persist($payment);
        $this->manager->flush();

        // The sale the event will name. Without it nothing resolves the payment, which is the
        // whole mechanism this test is about.
        $this->recorder()->record($payment, $this->approved($this->sale), NmiTransactionInterface::TYPE_SALE);
        $this->manager->flush();

        return $payment;
    }
}
