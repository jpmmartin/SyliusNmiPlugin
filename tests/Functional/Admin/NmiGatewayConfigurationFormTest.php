<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Admin;

use JpmMartin\SyliusNmiPlugin\Form\Type\NmiGatewayConfigurationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * The gateway configuration form as an operator reads it.
 *
 * Every label and help text in this form is a translation key, which means a template rendering
 * it can be perfectly correct and still show the key itself — a missing catalogue entry does not
 * fail, it just prints `jpm_martin_sylius_nmi.form...` where a sentence should be. The only way
 * to know is to render the form and read the words, in each language the plugin claims to speak.
 */
final class NmiGatewayConfigurationFormTest extends KernelTestCase
{
    /** Label and help text, for every field the form has. */
    private const EXPECTED = [
        'en' => [
            'Tokenization key',
            'The public key with the Tokenization permission.',
            'Security key',
            'The private API key.',
            'Environment',
            'Sandbox sends transactions to NMI&#039;s sandbox gateway',
            'Choose an environment',
            'Production',
            'Sandbox',
            'Authorize first, capture later',
            'checkout only authorizes the card',
        ],
        'es' => [
            'Clave de tokenización',
            'La clave pública con el permiso Tokenization.',
            'Clave de seguridad',
            'La clave privada de la API.',
            'Entorno',
            'Sandbox envía las transacciones a la pasarela de pruebas de NMI',
            'Elige un entorno',
            'Producción',
            'Sandbox',
            'Autorizar primero, capturar después',
            'el checkout sólo autoriza la tarjeta',
        ],
    ];

    /** @return iterable<string, array{string, list<string>}> */
    public static function locales(): iterable
    {
        foreach (self::EXPECTED as $locale => $expected) {
            yield $locale => [$locale, $expected];
        }
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testTheFormSpeaksTheLocaleItIsRenderedIn(string $locale, array $expected): void
    {
        self::bootKernel();

        $html = $this->render($locale);

        foreach ($expected as $words) {
            self::assertStringContainsString($words, $html, sprintf('Missing from the %s form.', $locale));
        }

        self::assertStringNotContainsString(
            'jpm_martin_sylius_nmi.',
            $html,
            'An untranslated key reaches the operator as its own name, which reads as a bug in the store.',
        );
    }

    private function render(string $locale): string
    {
        $container = self::getContainer();

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = $container->get('translation.locale_switcher');

        /** @var FormFactoryInterface $formFactory */
        $formFactory = $container->get('form.factory');

        /** @var Environment $twig */
        $twig = $container->get('twig');

        return $localeSwitcher->runWithLocale($locale, function () use ($formFactory, $twig): string {
            // Embedded under the payment method form in the store, so it is never the root form
            // and never carries the token. Asking for one here would only need a session.
            $form = $formFactory->create(NmiGatewayConfigurationType::class, null, ['csrf_protection' => false])->createView();

            return $twig->createTemplate('{{ form_widget(form) }}')->render(['form' => $form]);
        });
    }
}
