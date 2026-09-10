<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Holds `docs/extending.md` to the code, in both directions.
 *
 * The page is the plugin's public surface and the versioning promise is made about it, so a name
 * on it that does not exist is a broken promise, and a class that is neither on it nor marked
 * `@internal` is a promise nobody meant to make. Both fail here rather than in a release.
 */
final class ExtendingSurfaceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const DOCUMENT = self::ROOT . '/docs/extending.md';

    private const SCRIPT = self::ROOT . '/assets/shop/js/nmi-payment.js';

    private const PLUGIN_NAMESPACE = 'JpmMartin\\SyliusNmiPlugin\\';

    public function testEveryClassNamedExists(): void
    {
        foreach ($this->spansMatching('/^JpmMartin\\\\SyliusNmiPlugin\\\\[A-Za-z0-9_\\\\]+$/') as $name) {
            self::assertTrue(class_exists($name) || interface_exists($name), sprintf('%s is named in docs/extending.md and does not exist.', $name));
        }
    }

    public function testEveryServiceNamedIsRegistered(): void
    {
        $hooks = $this->hooks();
        $config = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $this->files(self::ROOT . '/config', 'xml')));
        $resources = $this->resources();

        foreach ($this->spansMatching('/^jpm_martin_sylius_nmi\.[a-z_.]+$/') as $name) {
            if (isset($hooks[$name]) || 'jpm_martin_sylius_nmi.resources' === $name) {
                continue;
            }
            if (1 === preg_match('/^jpm_martin_sylius_nmi\.repository\.([a-z_]+)$/', $name, $match)) {
                self::assertContains($match[1], $resources, sprintf('%s names a resource the plugin does not declare.', $name));

                continue;
            }
            self::assertStringContainsString(sprintf('id="%s"', $name), $config, sprintf('%s is named in docs/extending.md and no service has that id.', $name));
        }
    }

    public function testEveryHookAndHookableNamedIsRegistered(): void
    {
        $hooks = $this->hooks();
        $document = (string) file_get_contents(self::DOCUMENT);

        foreach ($this->spansMatching('/^(jpm_martin_sylius_nmi\.shop\.|sylius_admin\.)[a-z_.]+$/') as $hook) {
            self::assertArrayHasKey($hook, $hooks, sprintf('%s is named as a hook and the plugin registers nothing on it.', $hook));
        }

        // The hooks table: every hookable a row names is registered on the hook the row names.
        preg_match_all('/^\| `([a-z_.]+)`[^|]*\| ([^|]+)\|$/m', $document, $rows, \PREG_SET_ORDER);
        self::assertNotEmpty($rows, 'The hooks table was not found.');
        foreach ($rows as [, $hook, $cell]) {
            preg_match_all('/`([a-z_]+)`/', $cell, $hookables);
            foreach ($hookables[1] as $hookable) {
                self::assertContains($hookable, $hooks[$hook] ?? [], sprintf('Hookable "%s" is not registered on %s.', $hookable, $hook));
            }
        }
    }

    public function testEveryRouteNamedExists(): void
    {
        $routes = [];
        foreach ($this->files(self::ROOT . '/config/routes', 'yaml') as $file) {
            $routes += (array) Yaml::parseFile($file);
        }

        foreach ($this->spansMatching('/^jpm_martin_sylius_nmi_[a-z_]+$/') as $route) {
            self::assertArrayHasKey($route, $routes, sprintf('%s is named as a route and none exists.', $route));
        }
    }

    public function testTheConsoleCommandNamedExists(): void
    {
        $sources = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $this->files(self::ROOT . '/src', 'php')));

        foreach ($this->spansMatching('/^jpm-martin:[a-z:-]+$/') as $command) {
            self::assertStringContainsString(sprintf("'%s'", $command), $sources, sprintf('Console command %s is named and none is declared.', $command));
        }
    }

    public function testEveryEventAndExportNamedIsInTheScript(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        foreach ($this->spansMatching('/^nmi:[a-z]+$/') as $event) {
            self::assertStringContainsString(sprintf("'%s'", $event), $script, sprintf('Event %s is named and the script never dispatches it.', $event));
        }

        self::assertSame(1, preg_match('/^export \{ ([a-zA-Z, ]+) \};$/m', $script, $match), 'The script exports nothing.');
        $exports = array_map('trim', explode(',', $match[1]));
        foreach (['mount', 'mountAll', 'submit'] as $export) {
            self::assertContains($export, $exports, sprintf('%s is documented as an export and is not exported.', $export));
        }
    }

    public function testEveryAttributeNamedIsRenderedOrRead(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);
        $templates = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $this->files(self::ROOT . '/templates', 'twig')));

        foreach ($this->spansMatching('/^data-nmi-[a-z0-9-]+$/') as $attribute) {
            $datasetKey = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', substr($attribute, 5)))));
            $rendered = str_contains($templates, $attribute) || str_contains($script, $attribute);
            $read = str_contains($script, 'dataset.' . $datasetKey) || str_contains($script, sprintf('[%s]', $attribute));
            self::assertTrue($rendered || $read, sprintf('%s is documented and neither a template renders it nor the script reads it.', $attribute));
        }
    }

    public function testEveryClassIsEitherNamedOrInternal(): void
    {
        $named = array_flip($this->spansMatching('/^JpmMartin\\\\SyliusNmiPlugin\\\\[A-Za-z0-9_\\\\]+$/'));
        $unclassified = [];
        $wrongly = [];

        foreach ($this->files(self::ROOT . '/src', 'php') as $file) {
            $relative = substr($file, \strlen(self::ROOT . '/src/'), -4);
            // Migrations are run, never extended: neither public nor internal.
            if (str_starts_with($relative, 'Migrations/')) {
                continue;
            }
            $class = self::PLUGIN_NAMESPACE . str_replace('/', '\\', $relative);
            $internal = str_contains((string) file_get_contents($file), '@internal');

            if (!isset($named[$class]) && !$internal) {
                $unclassified[] = $class;
            }
            if (isset($named[$class]) && $internal) {
                $wrongly[] = $class;
            }
        }

        self::assertSame([], $unclassified, 'Named in docs/extending.md or marked @internal: every class chooses. These chose neither.');
        self::assertSame([], $wrongly, 'Named as public in docs/extending.md and marked @internal at once.');
    }

    /** @return list<string> */
    private function spansMatching(string $pattern): array
    {
        preg_match_all('/`([^`\n]+)`/', (string) file_get_contents(self::DOCUMENT), $matches);

        return array_values(array_unique(array_filter($matches[1], static fn (string $span): bool => 1 === preg_match($pattern, $span))));
    }

    /** @return array<string, list<string>> hook name to its hookables, as the plugin registers them */
    private function hooks(): array
    {
        $hooks = [];
        foreach ($this->files(self::ROOT . '/config/twig_hooks', 'yaml') as $file) {
            /** @var array{sylius_twig_hooks?: array{hooks?: array<string, array<string, mixed>>}} $parsed */
            $parsed = (array) Yaml::parseFile($file);
            foreach ($parsed['sylius_twig_hooks']['hooks'] ?? [] as $hook => $hookables) {
                $hooks[$hook] = array_merge($hooks[$hook] ?? [], array_map('strval', array_keys($hookables)));
            }
        }

        return $hooks;
    }

    /** @return list<string> */
    private function resources(): array
    {
        preg_match_all("/->arrayNode\\('(nmi_[a-z_]+)'\\)/", (string) file_get_contents(self::ROOT . '/src/DependencyInjection/Configuration.php'), $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private function files(string $directory, string $extension): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
