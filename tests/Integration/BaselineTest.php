<?php

declare(strict_types=1);

namespace FluidStan\Tests\Integration;

use FluidStan\Console\Application;
use FluidStan\Tests\TemporaryDirectory;
use Nette\Neon\Neon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The baseline round trip through the command: generate, include, analyse again. Without PHPStan,
 * which keeps it fast; the parser and transpiler diagnostics of the fixtures are enough.
 */
final class BaselineTest extends TestCase
{
    use TemporaryDirectory;

    public function testGeneratedBaselineSilencesTheErrorsItRecords(): void
    {
        $fixtures = (string) realpath(__DIR__ . '/../Fixtures');
        $decoded = Neon::decode((string) file_get_contents($fixtures . '/fluidstan.neon'));
        $this->assertIsArray($decoded);
        $parameters = $decoded['parameters'] ?? null;
        $this->assertIsArray($parameters);
        $paths = $parameters['paths'] ?? null;
        $this->assertIsArray($paths);
        $parameters['paths'] = array_map(static fn(mixed $path): string => $fixtures . '/' . (\is_string($path) ? $path : ''), $paths);
        $parameters['tmpDir'] = $this->tmpDir . '/cache';
        // PHPStan's loader caches a file's content for the process, so every step writes a fresh configuration file
        $configFile = $this->configFile(['parameters' => $parameters]);

        $before = $this->analyse(['--configuration' => $configFile, '--no-phpstan' => true]);
        $this->assertSame(1, $before['status']);
        $this->assertStringContainsString('Strict mode is off', $before['output'], 'undecided strict mode is pointed out');

        $generated = $this->analyse(['--configuration' => $configFile, '--no-phpstan' => true, '--generate-baseline' => null]);
        $this->assertSame(0, $generated['status'], $generated['output']);
        $this->assertFileExists($this->tmpDir . '/fluidstan-baseline.neon');
        $this->assertMatchesRegularExpression('/Baseline with \\d+ errors written to/', $generated['output']);
        $this->assertStringContainsString('Templates/Arguments.html', (string) file_get_contents($this->tmpDir . '/fluidstan-baseline.neon'));

        $configFile = $this->configFile(['includes' => ['fluidstan-baseline.neon'], 'parameters' => $parameters]);
        $after = $this->analyse(['--configuration' => $configFile, '--no-phpstan' => true]);
        $this->assertSame(0, $after['status'], $after['output']);
        $this->assertStringContainsString('No errors', $after['output']);

        // An entry that matches nothing in an analysed template is reported, as PHPStan does
        $parameters['ignoreErrors'] = [['message' => '#never#', 'path' => $fixtures . '/Templates/Plain.html']];
        $configFile = $this->configFile(['includes' => ['fluidstan-baseline.neon'], 'parameters' => $parameters]);
        $stale = $this->analyse(['--configuration' => $configFile, '--no-phpstan' => true]);
        $this->assertSame(1, $stale['status']);
        $this->assertStringContainsString('was not matched', $stale['output']);
        $this->assertStringContainsString('ignore.unmatched', $stale['output']);

        $parameters['reportUnmatchedIgnoredErrors'] = false;
        $parameters['strict'] = false;
        $configFile = $this->configFile(['includes' => ['fluidstan-baseline.neon'], 'parameters' => $parameters]);
        $quiet = $this->analyse(['--configuration' => $configFile, '--no-phpstan' => true]);
        $this->assertSame(0, $quiet['status'], $quiet['output']);
        $this->assertStringNotContainsString('Strict mode is off', $quiet['output'], 'a decision made is not questioned');
    }

    public function testTheBaselineMustBeNeon(): void
    {
        $result = $this->analyse(['--configuration' => (string) realpath(__DIR__ . '/../Fixtures/fluidstan.neon'), '--no-phpstan' => true, '--generate-baseline' => 'baseline.json']);
        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('extension .neon', $result['output']);
    }

    private int $configFiles = 0;

    /**
     * @param array<string, mixed> $content
     */
    private function configFile(array $content): string
    {
        $file = \sprintf('%s/fluidstan-%d.neon', $this->tmpDir, ++$this->configFiles);
        file_put_contents($file, Neon::encode($content, true));
        return $file;
    }

    /**
     * @param array<string, string|bool|null> $options
     * @return array{status: int, output: string}
     */
    private function analyse(array $options): array
    {
        $tester = new CommandTester((new Application(\dirname(__DIR__, 2) . '/vendor/autoload.php'))->find('analyse'));
        $status = $tester->execute($options);
        return ['status' => $status, 'output' => $tester->getDisplay()];
    }
}
