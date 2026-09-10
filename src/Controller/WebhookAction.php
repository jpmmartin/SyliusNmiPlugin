<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Controller;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiReceivedEventLedgerInterface;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookEnvelope;
use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookRouterInterface;
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
 *
 * @internal
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

    /**
     * The largest delivery this will read.
     *
     * **Deliberately generous, and the reason is not timidity.** A real transaction event was
     * measured at 2.5 KB, but settlement, chargebacks and card updates arrive as batches with an
     * entry per item, and refusing one that is genuine loses its contents *and* earns twenty
     * retries over three days. Four megabytes is tens of thousands of entries.
     *
     * What it buys is a ceiling on work an unsigned request can cause: without it, anyone can make
     * the store compute an HMAC over as much data as they care to send. The deployment's own
     * `post_max_size` is the first line; this is the plugin refusing to depend on it.
     */
    private const LARGEST_ACCEPTED_BODY = 4 * 1024 * 1024;

    /**
     * How much of the payment method code reaches the log.
     *
     * The route already bounds it, so this is the second lock on the same door: the code is
     * chosen by whoever made the request, and a log line whose length they control is a disk they
     * can fill.
     */
    private const LOGGED_CODE_LENGTH = 64;

    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiReceivedEventLedgerInterface $ledger,
        private readonly NmiWebhookRouterInterface $router,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $code): Response
    {
        $paymentMethod = $this->nmiPaymentMethodFor($code);
        $signingKey = null === $paymentMethod
            ? null
            : $this->configurationProvider->fromPaymentMethod($paymentMethod)->webhookSigningKey;

        if (null === $paymentMethod || null === $signingKey) {
            // Indistinguishable from a bad signature on purpose. Answering differently would let
            // anyone enumerate which payment method codes exist and which of them take webhooks.
            return $this->reject($code, 'no payment method with a signing key answers to that code');
        }

        // Read once, and raw. Anything that re-encodes the body — decoding to an array and back,
        // or trusting a parsed form — changes the bytes the signature was computed over.
        $body = $request->getContent();

        // Before the HMAC, which is the point: hashing is the work an unsigned request would
        // otherwise get for free, and it is proportional to what the sender chose to send.
        if (strlen($body) > self::LARGEST_ACCEPTED_BODY) {
            $this->logger->error('Refused an oversized NMI webhook delivery.', [
                'payment_method_code' => self::loggable($code),
                'bytes' => strlen($body),
                'limit' => self::LARGEST_ACCEPTED_BODY,
            ]);

            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if (!NmiWebhookSignature::isValid($request->headers->get(NmiWebhookSignature::HEADER), $body, $signingKey)) {
            return $this->reject($code, 'the signature did not match');
        }

        // Everything past this line is trusted to have come from the gateway.
        $envelope = NmiWebhookEnvelope::fromBody($body);
        if (null === $envelope) {
            // Malformed, so redelivering the same bytes cannot help and a retry would be twenty
            // pointless deliveries. Nothing is written.
            $this->logger->error('An NMI webhook delivery could not be read as an event envelope.', [
                'payment_method_code' => self::loggable($code),
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

        // Recorded first, acted on second, and acknowledged whatever the router made of it. A
        // delivery this store cannot use is not a delivery worth twenty retries.
        $this->router->route($envelope, $paymentMethod);

        return $this->accepted();
    }

    /**
     * The NMI payment method that answers to this code, or null when none does.
     *
     * Null and "configured with no signing key" are answered identically by the caller, and that
     * is the point: a store that has not wired webhooks is not a misconfiguration to complain
     * about, and telling the two apart in the response would let anyone enumerate which methods
     * exist and which of them take events.
     */
    private function nmiPaymentMethodFor(string $code): ?PaymentMethodInterface
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $code]);

        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return null;
        }

        return NmiGatewayFactory::NAME === $paymentMethod->getGatewayConfig()?->getFactoryName() ? $paymentMethod : null;
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
            'payment_method_code' => self::loggable($code),
        ]);

        return new Response('', self::REJECTED);
    }

    /** As much of an attacker-chosen string as is worth writing down. */
    private static function loggable(string $code): string
    {
        return substr($code, 0, self::LOGGED_CODE_LENGTH);
    }
}
