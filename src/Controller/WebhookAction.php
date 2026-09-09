<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Controller;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiReceivedEventLedgerInterface;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookEnvelope;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookSignature;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the gateway delivers what it did outside this store.
 *
 * **This is the largest attack surface the plugin has**: a public endpoint, unauthenticated by
 * construction, reachable by anyone who guesses the URL. The only thing between it and the
 * database is the signature check, so the order of operations here is the design, not an
 * implementation detail — nothing is decoded, looked up or written before the body has been proven
 * to come from the gateway.
 *
 * **Bound to a payment method by its code**, which is what makes the account unambiguous: the
 * signing key belongs to one NMI account and a store may have two. The code is not a secret and
 * is not treated as one — it selects which key to verify against, it does not authorise anything.
 *
 * **Why not the framework's notify route.** Half of these events name no payment at all, the
 * provider list carries no gateway discriminator so a plugin can answer another gateway's
 * deliveries, its response provider is `final` and shared with every payment plugin, the whole
 * contract is `@experimental`, and nothing on that path deduplicates. Those five reasons are
 * recorded in the change's design; a sixth, about the response status forcing retries, was
 * withdrawn after the gateway was observed accepting a `204`.
 */
final class WebhookAction
{
    /**
     * What a rejected delivery answers.
     *
     * A rejection must not look like something worth retrying: the gateway would redeliver for
     * three days and the store would be answering `401` to every one of them.
     */
    private const REJECTED = Response::HTTP_UNAUTHORIZED;

    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiReceivedEventLedgerInterface $ledger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $code): Response
    {
        $signingKey = $this->signingKeyFor($code);
        if (null === $signingKey) {
            // Indistinguishable from a bad signature on purpose. Answering differently would let
            // anyone enumerate which payment method codes exist and which of them take webhooks.
            return $this->reject($code, 'no payment method with a signing key answers to that code');
        }

        // Read once, and raw. Anything that re-encodes the body — decoding to an array and back,
        // or trusting a parsed form — changes the bytes the signature was computed over.
        $body = $request->getContent();

        if (!NmiWebhookSignature::isValid($request->headers->get(NmiWebhookSignature::HEADER), $body, $signingKey)) {
            return $this->reject($code, 'the signature did not match');
        }

        // Everything past this line is trusted to have come from the gateway.
        $envelope = NmiWebhookEnvelope::fromBody($body);
        if (null === $envelope) {
            // Malformed, so redelivering the same bytes cannot help and a retry would be twenty
            // pointless deliveries. Nothing is written.
            $this->logger->error('An NMI webhook delivery could not be read as an event envelope.', [
                'payment_method_code' => $code,
            ]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // **Before any side effect, and this order is the design.** A crash between recording and
        // applying loses an event, which the gateway's retries cover; a crash the other way round
        // applies one twice, which nothing covers.
        if (!$this->ledger->accept($envelope, $body, $code)) {
            $this->logger->info('Ignored a repeated NMI webhook delivery.', [
                'event_id' => $envelope->eventId,
                'event_type' => $envelope->eventType,
            ]);

            // Success, not an error: the point is that the gateway stops redelivering.
            return $this->accepted();
        }

        // Routing on the event type, and the side effects themselves, land here as the change
        // proceeds. Until then every event takes the path a type this store does not act on will
        // always take: recorded, logged and acknowledged.
        //
        // Logged rather than silently dropped, because an account shared with another store
        // produces types this one has no use for continuously, and an operator wondering why
        // nothing happened deserves to see that the delivery did arrive.
        $this->logger->info('Recorded an NMI webhook delivery this store does not act on.', [
            'event_id' => $envelope->eventId,
            'event_type' => $envelope->eventType,
            'payment_method_code' => $code,
        ]);

        return $this->accepted();
    }

    /**
     * The key this method's deliveries are signed with, or null when there is nothing to verify
     * against — an unknown code, a method belonging to another gateway, or one whose operator
     * never pasted a signing key.
     *
     * A method with no key is not a misconfiguration to complain about. It is the ordinary state
     * of a store that has not wired webhooks, and the promise that such a store sees no change is
     * kept precisely by refusing here.
     */
    private function signingKeyFor(string $code): ?string
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $code]);

        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return null;
        }

        if (NmiGatewayFactory::NAME !== $paymentMethod->getGatewayConfig()?->getFactoryName()) {
            return null;
        }

        return $this->configurationProvider->fromPaymentMethod($paymentMethod)->webhookSigningKey;
    }

    /**
     * What a delivery that got past the signature is answered with — always, whatever happened
     * afterwards.
     *
     * `200` specifically. A `204` was observed to stop the retries too, but the gateway's own
     * documentation says only `200` does, and there is no reason to spend the difference on a
     * behaviour it does not promise.
     */
    private function accepted(): Response
    {
        return new Response('', Response::HTTP_OK);
    }

    /**
     * Logs at error level, because the symptom of a rotated signing key is otherwise complete
     * silence: every delivery fails, the gateway gives up after three days, and nothing in the
     * store ever says so.
     *
     * The reason is logged; **the body is not**, and neither is the header. An attacker chooses
     * both, so writing either into the log hands them the volume of the log file.
     */
    private function reject(string $code, string $reason): Response
    {
        $this->logger->error('Rejected an NMI webhook delivery: {reason}.', [
            'reason' => $reason,
            'payment_method_code' => $code,
        ]);

        return new Response('', self::REJECTED);
    }
}
