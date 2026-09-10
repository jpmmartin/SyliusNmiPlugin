<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

/**
 * Proves a delivery came from the gateway, which is the only thing standing between a public
 * endpoint and this store's payments.
 *
 * **The scheme was confirmed against real deliveries, not read off a page.** The header is two
 * components, the signed material is the nonce and the raw body joined by a dot, and the digest is
 * hexadecimal:
 *
 * ```
 * Webhook-Signature:  t=<nonce>,s=<signature>
 * signed material:    <nonce> . "." . <raw body>
 * digest:             hex(hmac_sha256(material, signing key))
 * ```
 *
 * Getting any of that wrong rejects every delivery, and the symptom is identical to a wrong key —
 * which is why it was settled by recomputing two captured deliveries by hand before this was
 * written, and why the alternatives it might plausibly have been (base64 of the same digest; the
 * body signed without the nonce) are pinned as *not* matching in the tests.
 *
 * **The nonce is a Unix timestamp and this deliberately ignores that.** Rejecting an old one would
 * look like replay protection and would in fact reject the gateway's own retries, which continue
 * for three days. Replay is handled where it belongs: a unique index on the event identifier.
 *
 * @internal
 */
final class NmiWebhookSignature
{
    public const HEADER = 'Webhook-Signature';

    private const NONCE_COMPONENT = 't';

    private const SIGNATURE_COMPONENT = 's';

    private function __construct()
    {
    }

    /**
     * Whether this body, with this header, was signed with this key.
     *
     * False for every way of failing — no header, a shape this cannot read, a signature that does
     * not match — because the caller has exactly one thing to do about any of them and telling
     * them apart in the answer would only invite a caller to treat some as less serious.
     */
    public static function isValid(?string $header, string $body, string $signingKey): bool
    {
        $components = self::componentsOf($header);
        $nonce = $components[self::NONCE_COMPONENT] ?? null;
        $signature = $components[self::SIGNATURE_COMPONENT] ?? null;

        if (null === $nonce || null === $signature || '' === $signingKey) {
            return false;
        }

        // Constant time, and it has to be: comparing with === leaks how many leading characters
        // were right, which is enough to forge a signature one character at a time.
        return hash_equals(
            hash_hmac('sha256', $nonce . '.' . $body, $signingKey),
            $signature,
        );
    }

    /**
     * Splits the header into its named components.
     *
     * The gateway's own published example parses this with `/t=(.*),s=(.*)/`, which assumes two
     * components in that order and nothing else. Reading it as a set of `name=value` pairs instead
     * costs nothing and survives a third component being added later — while still refusing
     * anything that does not name both of the two this needs.
     *
     * @return array<string, string>
     */
    private static function componentsOf(?string $header): array
    {
        if (null === $header || '' === trim($header)) {
            return [];
        }

        $components = [];

        foreach (explode(',', $header) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (2 !== count($parts)) {
                continue;
            }

            [$name, $value] = $parts;
            $name = trim($name);
            $value = trim($value);

            if ('' !== $name && '' !== $value) {
                $components[$name] = $value;
            }
        }

        return $components;
    }
}
