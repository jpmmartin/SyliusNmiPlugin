<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Webhook;

use JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * The one check standing between a public endpoint and this store's payments.
 *
 * **The scheme here was settled against the gateway before any of this was written**, by capturing
 * two real deliveries and recomputing their signatures by hand. That matters because getting it
 * wrong rejects every delivery with a symptom identical to a wrong key — so the two shapes it
 * might plausibly have been are pinned as *not* matching, not merely left untested.
 */
final class NmiWebhookSignatureTest extends TestCase
{
    private const KEY = 'a-signing-key';

    private const NONCE = '1788895697';

    private const BODY = '{"event_id":"e57af459-274f-4b15-8aa9-a0852dbc32fb","event_type":"transaction.sale.success"}';

    public function testADeliverySignedWithTheKeyIsAccepted(): void
    {
        self::assertTrue(NmiWebhookSignature::isValid($this->header($this->correctSignature()), self::BODY, self::KEY));
    }

    public function testADeliverySignedWithAnotherKeyIsRefused(): void
    {
        $signature = hash_hmac('sha256', self::NONCE . '.' . self::BODY, 'somebody-elses-key');

        self::assertFalse(NmiWebhookSignature::isValid($this->header($signature), self::BODY, self::KEY));
    }

    /** A body altered after signing, which is the whole point of signing it. */
    public function testADeliveryWhoseBodyChangedIsRefused(): void
    {
        self::assertFalse(NmiWebhookSignature::isValid($this->header($this->correctSignature()), self::BODY . ' ', self::KEY));
    }

    /**
     * The nonce is signed, so reusing a valid signature under a different one fails. This is not
     * replay protection — the gateway's own retries repeat the nonce too — it is what makes the
     * timestamp part of the material rather than decoration.
     */
    public function testTheNonceIsPartOfWhatIsSigned(): void
    {
        self::assertFalse(NmiWebhookSignature::isValid('t=1788895698,s=' . $this->correctSignature(), self::BODY, self::KEY));
    }

    /**
     * **The two shapes this might have been.** Both were computed and compared against a real
     * delivery, and neither matched; they are pinned here so a future edit that "simplifies" the
     * digest cannot pass.
     *
     * @dataProvider theShapesItIsNot
     */
    public function testAPlausibleButWrongDigestIsRefused(string $signature): void
    {
        self::assertFalse(NmiWebhookSignature::isValid($this->header($signature), self::BODY, self::KEY));
    }

    /** @return iterable<string, array{string}> */
    public static function theShapesItIsNot(): iterable
    {
        yield 'base64 rather than hexadecimal' => [base64_encode(hash_hmac('sha256', self::NONCE . '.' . self::BODY, self::KEY, true))];
        yield 'the body alone, without the nonce' => [hash_hmac('sha256', self::BODY, self::KEY)];
        yield 'the nonce and body without the separating dot' => [hash_hmac('sha256', self::NONCE . self::BODY, self::KEY)];
    }

    /**
     * @dataProvider headersItCannotRead
     */
    public function testAHeaderItCannotReadIsRefused(?string $header): void
    {
        self::assertFalse(NmiWebhookSignature::isValid($header, self::BODY, self::KEY));
    }

    /** @return iterable<string, array{string|null}> */
    public static function headersItCannotRead(): iterable
    {
        yield 'no header at all' => [null];
        yield 'an empty header' => [''];
        yield 'whitespace' => ['   '];
        yield 'the signature without the nonce' => ['s=' . hash_hmac('sha256', self::NONCE . '.' . self::BODY, self::KEY)];
        yield 'the nonce without the signature' => ['t=' . self::NONCE];
        yield 'neither component named' => ['1788895697,deadbeef'];
        yield 'a component with no value' => ['t=,s='];
    }

    /**
     * A component the gateway has not sent yet does not break the parse, and the order is not
     * assumed. The published example parses this with `/t=(.*),s=(.*)/`, which would fail both.
     */
    public function testTheComponentsAreReadByNameRatherThanByPosition(): void
    {
        self::assertTrue(NmiWebhookSignature::isValid(
            'v=2, s=' . $this->correctSignature() . ' ,t=' . self::NONCE,
            self::BODY,
            self::KEY,
        ));
    }

    /** No key configured is no basis for trusting anything, so it refuses rather than defaults. */
    public function testWithNoSigningKeyNothingIsValid(): void
    {
        self::assertFalse(NmiWebhookSignature::isValid($this->header($this->correctSignature()), self::BODY, ''));
    }

    /**
     * **That the constant-time comparison is the one that actually runs**, read off the code that
     * runs rather than inferred from behaviour — because `===` and `hash_equals` are behaviourally
     * identical and differ only in what they leak. A timing measurement would be the alternative
     * and it would be flaky; this cannot be.
     *
     * `===` on the digest leaks how many leading characters were right, which is enough to forge a
     * signature one character at a time against an endpoint that answers quickly.
     */
    public function testTheComparisonIsConstantTime(): void
    {
        $method = new \ReflectionMethod(NmiWebhookSignature::class, 'isValid');
        $source = implode("\n", array_slice(
            file((string) $method->getFileName()) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString('hash_equals(', $source, 'The signature must be compared with hash_equals().');
        self::assertDoesNotMatchRegularExpression(
            '/\$signature\s*(===|==|!=|!==)/',
            $source,
            'The signature must never be compared with an operator, only with hash_equals().',
        );
    }

    private function correctSignature(): string
    {
        return hash_hmac('sha256', self::NONCE . '.' . self::BODY, self::KEY);
    }

    private function header(string $signature): string
    {
        return sprintf('t=%s,s=%s', self::NONCE, $signature);
    }
}
