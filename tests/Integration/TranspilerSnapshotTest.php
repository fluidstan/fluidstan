<?php

declare(strict_types=1);

namespace FluidStan\Tests\Integration;

use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Fluid\FluidEnvironment;
use FluidStan\Transpiler\TemplateTranspiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The generated PHP is the contract between the transpiler and the PHPStan extension. Any change
 * in it shows up here; update the snapshots with UPDATE_SNAPSHOTS=1 after reviewing the diff.
 */
final class TranspilerSnapshotTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        yield 'component with model argument' => ['Components/Atom/Price/Price.html'];
        yield 'component calling components' => ['Components/Molecule/Card/Card.html'];
        yield 'plain template with annotation' => ['Templates/Plain.html'];
        yield 'syntax coverage' => ['Templates/Syntax.html'];
    }

    #[DataProvider('templates')]
    public function testGeneratedCodeMatchesSnapshot(string $template): void
    {
        $root = __DIR__ . '/../Fixtures';
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', $root);
        $parsed = (new FluidEnvironment($configuration))->parseFile($root . '/' . $template);
        $this->assertTrue($parsed->isParsed());
        $this->assertCount(0, $parsed->parseDiagnostics);

        $generated = (new TemplateTranspiler())->transpile($parsed);
        // The header carries the absolute template path and the function name hashes it; keep
        // snapshots machine independent
        $code = str_replace($root . '/', '', $generated->code);
        $code = preg_replace('/fluidstan_template_[0-9a-f]{32}/', 'fluidstan_template_HASH', $code) ?? $code;

        $snapshot = __DIR__ . '/__snapshots__/' . str_replace('/', '_', $template) . '.php';
        if (getenv('UPDATE_SNAPSHOTS') === '1' || !is_file($snapshot)) {
            file_put_contents($snapshot, $code);
        }

        $this->assertStringEqualsFile($snapshot, $code);
    }
}
