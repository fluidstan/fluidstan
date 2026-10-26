<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\ConfigurationException;
use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Tests\Fixtures\Components\ComponentCollection;
use FluidStan\Tests\Fixtures\Documented\DocumentedCollection;
use PHPUnit\Framework\TestCase;

final class ConfigurationLoaderTest extends TestCase
{
    public function testDefaults(): void
    {
        $configuration = (new ConfigurationLoader())->fromParameters([], '/project');

        $this->assertSame('/project', $configuration->rootDir);
        $this->assertSame([], $configuration->paths);
        $this->assertSame(['.html'], $configuration->suffixes);
        $this->assertSame(['f' => ['TYPO3Fluid\\Fluid\\ViewHelpers']], $configuration->namespaces);
        $this->assertSame([], $configuration->bootstrapFiles);
        $this->assertSame(8, $configuration->level);
        $this->assertNull($configuration->phpstanBinary);
        $this->assertSame([], $configuration->phpstanIncludes);
        $this->assertSame([], $configuration->phpstanParameters);
        $this->assertSame('/project/.cache/fluidstan', $configuration->tmpDir);
        $this->assertNull($configuration->environment);
    }

    public function testParametersAndPathResolution(): void
    {
        $configuration = (new ConfigurationLoader())->fromParameters([
            'paths' => ['Components', '/abs/Templates', 'EXT:site/Resources'],
            'suffixes' => '.fluid.html',
            'namespaces' => ['my' => 'My\\Collection', 'f' => ['A\\B', 'C\\D']],
            'bootstrapFiles' => ['vendor/autoload.php'],
            'tmpDir' => 'var/fluidstan',
            'environment' => 'My\\Environment',
            'phpstan' => ['level' => '6', 'binary' => 'bin/phpstan', 'includes' => ['phpstan.neon'], 'parameters' => ['checkFoo' => true]],
        ], '/project');

        $this->assertSame(['/project/Components', '/abs/Templates', 'EXT:site/Resources'], $configuration->paths);
        $this->assertSame(['.fluid.html'], $configuration->suffixes);
        $this->assertSame(['f' => ['A\\B', 'C\\D'], 'my' => ['My\\Collection']], $configuration->namespaces);
        $this->assertSame(['/project/vendor/autoload.php'], $configuration->bootstrapFiles);
        $this->assertSame('/project/var/fluidstan', $configuration->tmpDir);
        $this->assertSame('My\\Environment', $configuration->environment);
        $this->assertSame(6, $configuration->level);
        $this->assertSame('/project/bin/phpstan', $configuration->phpstanBinary);
        $this->assertSame(['/project/phpstan.neon'], $configuration->phpstanIncludes);
        $this->assertSame(['checkFoo' => true], $configuration->phpstanParameters);
    }

    public function testLoadsNeonRelativeToTheConfigFile(): void
    {
        $root = (string) realpath(__DIR__ . '/../Fixtures');
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', '/elsewhere');

        $this->assertSame($root, $configuration->rootDir);
        $this->assertSame([$root . '/Components', $root . '/Documented', $root . '/Templates'], $configuration->paths);
        $this->assertSame(['fs' => [ComponentCollection::class], 'fd' => [DocumentedCollection::class]], array_diff_key($configuration->namespaces, ['f' => true]));
    }

    public function testMissingConfigFileIsAnError(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigurationLoader())->load('/nope/fluidstan.neon', '/nope');
    }

    public function testInvalidListIsAnError(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigurationLoader())->fromParameters(['paths' => [['nested']]], '/project');
    }

    public function testWithersKeepEverythingElse(): void
    {
        $configuration = (new ConfigurationLoader())->fromParameters(['paths' => ['a'], 'environment' => 'E'], '/p');
        $changed = $configuration->withPaths(['/x'])->withLevel(3)->withTmpDir('/tmp/t');

        $this->assertSame(['/x'], $changed->paths);
        $this->assertSame(3, $changed->level);
        $this->assertSame('/tmp/t', $changed->tmpDir);
        $this->assertSame('E', $changed->environment);
        $this->assertSame($configuration->namespaces, $changed->namespaces);
    }
}
