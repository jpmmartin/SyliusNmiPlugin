<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The two catalogues, held to each other.
 *
 * `debug:translation es --only-missing` answers the same question, but it answers it about the
 * whole application — a Sylius install has gaps of its own, so its output is never empty and a
 * person reading it has to filter this plugin's keys out by eye every time. This asserts the part
 * that is actually this plugin's obligation, and it fails a build rather than a reading.
 *
 * It is deliberately about *keys*, not words. Whether a translation says the same thing is a
 * judgement no test can make; what a test can hold is that every key exists on both sides and that
 * neither catalogue has grown a key the other has not.
 */
final class NmiCatalogueParityTest extends TestCase
{
    private const DOMAINS = ['messages', 'flashes', 'validators'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function domains(): iterable
    {
        foreach (self::DOMAINS as $domain) {
            yield $domain => [$domain];
        }
    }

    /**
     * @dataProvider domains
     */
    public function testEveryKeyExistsInBothLanguages(string $domain): void
    {
        $english = array_keys($this->catalogue($domain, 'en'));
        $spanish = array_keys($this->catalogue($domain, 'es'));

        sort($english);
        sort($spanish);

        self::assertSame(
            $english,
            $spanish,
            sprintf('The %s catalogues have drifted apart. A key on one side only reaches an operator untranslated.', $domain),
        );
    }

    /**
     * A key whose translation is the key again, or empty, is a gap that parity alone would not
     * catch — both catalogues would have it and both would be useless.
     *
     * @dataProvider domains
     */
    public function testNoTranslationIsEmptyOrAnEchoOfItsOwnKey(string $domain): void
    {
        foreach (['en', 'es'] as $locale) {
            foreach ($this->catalogue($domain, $locale) as $key => $message) {
                self::assertNotSame('', trim($message), sprintf('%s.%s.%s is empty.', $domain, $locale, $key));
                self::assertStringNotContainsString(
                    'jpm_martin_sylius_nmi.',
                    $message,
                    sprintf('%s.%s.%s reads back a translation key rather than a sentence.', $domain, $locale, $key),
                );
            }
        }
    }

    /**
     * The help text is the one place where a translation that lost the meaning would be worse than
     * a missing one: it is the only thing an operator has to decide from, and it makes claims about
     * what an issuer does and where liability sits.
     *
     * So the claims are named here rather than trusted. The rendered form is checked separately —
     * this is about the words themselves, in the file, in both languages.
     */
    public function testTheAuthenticationHelpTextCarriesItsWarningInBothLanguages(): void
    {
        $claims = [
            'en' => [
                'one click',
                'the issuer may decline a payment that carries no authentication',
                'liability for a chargeback stays with you',
                'North America',
            ],
            'es' => [
                'un solo clic',
                'el emisor puede rechazar un pago que no lleve autenticación',
                'la responsabilidad por un contracargo se queda en la tienda',
                'Norteamérica',
            ],
        ];

        foreach ($claims as $locale => $expected) {
            $help = $this->catalogue('messages', $locale)['jpm_martin_sylius_nmi.form.gateway_config.authenticate_stored_cards_help'];

            foreach ($expected as $claim) {
                self::assertStringContainsString($claim, $help, sprintf('The %s help text no longer says "%s".', $locale, $claim));
            }
        }
    }

    /** @return array<string, string> */
    private function catalogue(string $domain, string $locale): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(sprintf('%s/translations/%s.%s.yaml', dirname(__DIR__, 3), $domain, $locale));

        return $this->flatten($parsed);
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }
}
