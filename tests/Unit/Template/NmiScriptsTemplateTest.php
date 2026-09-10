<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Template;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The script-tag template the plugin ships, and the two names it commits an application to.
 *
 * Rendered against a stub of Encore's function rather than a real build: the plugin's own test
 * application builds its entry under another name, so a real render there would fail for the
 * wrong reason. What the names do in a real store is the install job's assertion.
 */
final class NmiScriptsTemplateTest extends TestCase
{
    public function testItAsksEncoreForTheDocumentedEntryInTheDocumentedBuild(): void
    {
        $calls = [];

        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../templates'));
        $twig->addFunction(new TwigFunction('encore_entry_script_tags', static function (...$arguments) use (&$calls): string {
            $calls[] = $arguments;

            return '<script src="/build/app/shop/nmi-shop.js"></script>';
        }, ['is_safe' => ['html']]));

        $html = $twig->render('shop/scripts.html.twig');

        self::assertSame([['nmi-shop', null, 'app.shop']], $calls, 'The template must name the entry and the build the README documents, and nothing else.');
        self::assertStringContainsString('nmi-shop.js', $html);
        self::assertStringNotContainsString('{#', $html, 'The comment is for the integrator, not the page.');
    }
}
