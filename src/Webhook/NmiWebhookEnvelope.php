<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

/**
 * The three fields every delivery has, whatever it is about.
 *
 * The gateway wraps every event the same way — `event_id`, `event_type`, `event_body` — and only
 * the last of those differs between a sale, a settlement batch and a card-updater summary. So this
 * reads the wrapper and nothing else; what is inside is each handler's business.
 *
 * **`event_id` is what the store deduplicates on**, and it is a UUID in every delivery observed and
 * every sample published. It is read as an opaque string rather than validated as a UUID: refusing
 * a delivery because its identifier had a shape this plugin did not expect would be refusing money
 * news over a formatting opinion.
 *
 * @internal
 */
final class NmiWebhookEnvelope
{
    private function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        /** @var array<string, mixed> */
        public readonly array $eventBody,
    ) {
    }

    /**
     * Null when the body is not something this can read at all — not JSON, not an object, or
     * missing an identifier or a type. That is a `400`: the gateway sent something malformed and
     * redelivering the same bytes cannot help.
     *
     * An event *type* this store does not recognise is a different thing entirely and is not
     * handled here — it decodes fine, is recorded, and is answered with success.
     */
    public static function fromBody(string $body): ?self
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        $eventId = self::text($decoded['event_id'] ?? null);
        $eventType = self::text($decoded['event_type'] ?? null);

        if (null === $eventId || null === $eventType) {
            return null;
        }

        $eventBody = $decoded['event_body'] ?? [];

        return new self(
            eventId: $eventId,
            eventType: $eventType,
            // An event with no body is readable and simply carries nothing; that is not a reason
            // to refuse it, only a reason for its handler to find nothing.
            eventBody: is_array($eventBody) ? $eventBody : [],
        );
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        // Longer than the column, and therefore longer than anything the gateway has ever sent.
        // Truncating an identifier would be worse than refusing it: two different events could
        // then collide on the unique index and the second would be silently discarded as a
        // duplicate of the first.
        return '' === $value || strlen($value) > 64 ? null : $value;
    }
}
