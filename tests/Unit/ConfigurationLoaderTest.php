<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\ConfigurationException;
use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Tests\Fixtures\Components\ComponentCollection;
use FluidStan\Tests\Fixtures\Documented\DocumentedCollection;
use FluidStan\Tests\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationLoaderTest extends TestCase
{
    use TemporaryDirectory;

    public function testDefaults(): void
    {
        $configuration = (new ConfigurationLoader())->fromParameters([], '/project');

        $this->assertSame('/project', $configuration->rootDir);
        $this->assertSame([], $configuration->paths);
        $this->assertSame(['.html'], $configuration->suffixes);
        $this->assertSame(['f' => ['TYPO3Fluid\\Fluid\\ViewHelpers']], $configuration->namespaces);
        $this->assertSame([], $configuration->bootstrapFiles);
        $this->assertNull($configuration->phpstanBinary);
        $this->assertSame([], $configuration->phpstanIncludes);
        $this->assertSame([], $configuration->phpstanParameters);
        $this->assertSame('/project/.cache/fluidstan', $configuration->tmpDir);
        $this->assertNull($configuration->strict, 'undecided until the project says so');
        $this->assertSame([], $configuration->ignoreErrors);
        $this->assertTrue($configuration->reportUnmatchedIgnoredErrors);
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
            'strict' => true,
            'phpstan' => ['binary' => 'bin/phpstan', 'includes' => ['phpstan.neon'], 'parameters' => ['checkFoo' => true]],
            'viewHelperAssignments' => ['My\\LoadViewHelper' => ['item' => 'My\\Item', '{as}' => ['type' => 'string', 'scope' => 'children']]],
        ], '/project');

        $this->assertSame(['/project/Components', '/abs/Templates', 'EXT:site/Resources'], $configuration->paths);
        $this->assertSame(['.fluid.html'], $configuration->suffixes);
        $this->assertSame(['f' => ['A\\B', 'C\\D'], 'my' => ['My\\Collection']], $configuration->namespaces);
        $this->assertSame(['/project/vendor/autoload.php'], $configuration->bootstrapFiles);
        $this->assertSame('/project/var/fluidstan', $configuration->tmpDir);
        $this->assertSame('My\\Environment', $configuration->environment);
        $this->assertSame('/project/bin/phpstan', $configuration->phpstanBinary);
        $this->assertSame(['/project/phpstan.neon'], $configuration->phpstanIncludes);
        $this->assertSame(['checkFoo' => true], $configuration->phpstanParameters);
        $this->assertSame(['My\\LoadViewHelper' => ['item' => ['type' => 'My\\Item', 'scope' => 'template'], '{as}' => ['type' => 'string', 'scope' => 'children']]], $configuration->viewHelperAssignments);
        $this->assertTrue($configuration->strict);
    }

    public function testStrictMayBeFalse(): void
    {
        $this->assertFalse((new ConfigurationLoader())->fromParameters(['strict' => false], '/project')->strict);
    }

    public function testStrictMustBeABool(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("The item 'strict' expects to be null or bool, 'yes' given.");
        (new ConfigurationLoader())->fromParameters(['strict' => 'yes'], '/project');
    }

    public function testLoadsNeonRelativeToTheConfigFile(): void
    {
        $root = (string) realpath(__DIR__ . '/../Fixtures');
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', '/elsewhere');

        $this->assertSame($root, $configuration->rootDir);
        $this->assertSame([$root . '/Components', $root . '/Documented', $root . '/Templates'], $configuration->paths);
        $this->assertSame(['fs' => [ComponentCollection::class], 'fd' => [DocumentedCollection::class], 'fv' => ['FluidStan\\Tests\\Fixtures\\ViewHelpers']], array_diff_key($configuration->namespaces, ['f' => true]));
    }

    public function testViewHelperAssignmentTypesMustBeStrings(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("expects to be 'template'|'children'|'removed', 'everywhere' given.");
        (new ConfigurationLoader())->fromParameters(['viewHelperAssignments' => ['My\\LoadViewHelper' => ['item' => ['type' => 'My\\Item', 'scope' => 'everywhere']]]], '/project');
    }

    public function testPhpStanLevelIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"phpstan.level" is not supported');
        (new ConfigurationLoader())->fromParameters(['phpstan' => ['level' => 8]], '/project');
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
        $configuration = (new ConfigurationLoader())->fromParameters(['paths' => ['a'], 'environment' => 'E', 'strict' => true], '/p');
        $changed = $configuration->withPaths(['/x'])->withTmpDir('/tmp/t');

        $this->assertSame(['/x'], $changed->paths);
        $this->assertSame('/tmp/t', $changed->tmpDir);
        $this->assertSame('E', $changed->environment);
        $this->assertTrue($changed->strict);
        $this->assertSame($configuration->namespaces, $changed->namespaces);
    }

    public function testIncludesMergeParametersAndConcatenateIgnoreErrors(): void
    {
        file_put_contents($this->tmpDir . '/fluidstan.neon', "includes:\n    - fluidstan-baseline.neon\n    - sub/more.neon\nparameters:\n    suffixes: [.html]\n    strict: true\n    ignoreErrors:\n        - '#own#'\n");
        file_put_contents($this->tmpDir . '/fluidstan-baseline.neon', "parameters:\n    suffixes: [.fluid]\n    ignoreErrors:\n        -\n            message: '#^Variable \"x\" is not defined\\.$#'\n            identifier: variable.undefined\n            count: 2\n            path: Templates/A.html\n");
        mkdir($this->tmpDir . '/sub');
        file_put_contents($this->tmpDir . '/sub/more.neon', "parameters:\n    reportUnmatchedIgnoredErrors: false\n    ignoreErrors:\n        -\n            identifier: fluidstan.output.bool\n            paths: [a/*.html, /abs/b.html]\n");

        $configuration = (new ConfigurationLoader())->load($this->tmpDir . '/fluidstan.neon', '/elsewhere');

        $this->assertSame(['.fluid', '.html'], $configuration->suffixes, 'lists add up, as in PHPStan');
        $this->assertTrue($configuration->strict);
        $this->assertFalse($configuration->reportUnmatchedIgnoredErrors, 'an include may set what the main file leaves alone');
        $this->assertSame([
            // paths are relative to the file that defines them
            ['message' => '#^Variable "x" is not defined\\.$#', 'identifier' => 'variable.undefined', 'count' => 2, 'path' => $this->tmpDir . '/Templates/A.html'],
            ['identifier' => 'fluidstan.output.bool', 'paths' => [$this->tmpDir . '/sub/a/*.html', '/abs/b.html']],
            '#own#',
        ], $configuration->ignoreErrors);

        // Generating the baseline: its include is left out, even one that does not exist yet
        $forBaseline = (new ConfigurationLoader())->load($this->tmpDir . '/fluidstan.neon', '/elsewhere', $this->tmpDir . '/fluidstan-baseline.neon');
        $this->assertSame([['identifier' => 'fluidstan.output.bool', 'paths' => [$this->tmpDir . '/sub/a/*.html', '/abs/b.html']], '#own#'], $forBaseline->ignoreErrors);
        unlink($this->tmpDir . '/fluidstan-baseline.neon');
        $this->assertCount(2, (new ConfigurationLoader())->load($this->tmpDir . '/fluidstan.neon', '/elsewhere', $this->tmpDir . '/fluidstan-baseline.neon')->ignoreErrors);
        $this->assertFalse($forBaseline->withoutUnmatchedIgnoredErrorsReport()->reportUnmatchedIgnoredErrors);
    }

    public function testIncludedPathsResolveFromARelativeConfigurationPathToo(): void
    {
        mkdir($this->tmpDir . '/Build');
        file_put_contents($this->tmpDir . '/Build/fluidstan.neon', "includes: [fluidstan-baseline.neon]\nparameters: {}\n");
        file_put_contents($this->tmpDir . '/Build/fluidstan-baseline.neon', "parameters:\n    ignoreErrors:\n        -\n            message: '#x#'\n            path: ../Templates/A.html\n");

        $cwd = (string) getcwd();
        chdir($this->tmpDir);
        try {
            $configuration = (new ConfigurationLoader())->load('Build/fluidstan.neon', $this->tmpDir);
        } finally {
            chdir($cwd);
        }

        $this->assertSame([['message' => '#x#', 'path' => $this->tmpDir . '/Templates/A.html']], $configuration->ignoreErrors);
    }

    public function testAMissingIncludeIsAnError(): void
    {
        file_put_contents($this->tmpDir . '/a.neon', "includes: [missing.neon]\nparameters: {}\n");
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('could not be loaded');
        (new ConfigurationLoader())->load($this->tmpDir . '/a.neon', $this->tmpDir);
    }

    public function testAnIncludeCycleIsAnError(): void
    {
        file_put_contents($this->tmpDir . '/a.neon', "includes: [b.neon]\nparameters: {}\n");
        file_put_contents($this->tmpDir . '/b.neon', "includes: [a.neon]\nparameters: {}\n");
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('could not be loaded');
        (new ConfigurationLoader())->load($this->tmpDir . '/a.neon', $this->tmpDir);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidIgnoreErrors(): iterable
    {
        // Nette's messages, the ones PHPStan users know; the item path separator is left out of the assertions
        yield 'not a list' => [['message' => '#x#'], "The item 'ignoreErrors' expects to be list, array given."];
        yield 'invalid pattern' => [['#[unclosed#'], "Failed assertion 'a regular expression with delimiters'"];
        yield 'neither message nor identifier' => [[['path' => 'a.html']], "Failed assertion 'a message, rawMessage or identifier'"];
        yield 'path and paths' => [[['message' => '#x#', 'path' => 'a.html', 'paths' => ['b.html']]], "Failed assertion 'either path or paths'"];
        yield 'zero count' => [[['message' => '#x#', 'count' => 0]], "count' expects to be in range 1.., 0 given."];
        yield 'path not a string' => [[['message' => '#x#', 'path' => 1]], "path' expects to be string, 1 given."];
        yield 'unknown option' => [[['message' => '#x#', 'file' => 'a.html']], "Unexpected item 'ignoreErrors"];
        yield 'unknown parameter' => [[], "Unexpected item 'nope'"];
    }

    #[DataProvider('invalidIgnoreErrors')]
    public function testInvalidIgnoreErrorsAreRejected(mixed $entries, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        (new ConfigurationLoader())->fromParameters($entries === [] ? ['nope' => 1] : ['ignoreErrors' => $entries], '/project');
    }

    public function testAcceptsEveryShapePhpStanAccepts(): void
    {
        $entries = [
            ['messages' => ['#a#', '#b#'], 'path' => 'A.html'],
            ['rawMessage' => 'Exact text', 'paths' => ['*.html']],
            ['identifiers' => ['x.y', 'z'], 'reportUnmatched' => false],
        ];
        $this->assertSame([
            ['messages' => ['#a#', '#b#'], 'path' => '/project/A.html'],
            ['rawMessage' => 'Exact text', 'paths' => ['/project/*.html']],
            ['identifiers' => ['x.y', 'z'], 'reportUnmatched' => false],
        ], (new ConfigurationLoader())->fromParameters(['ignoreErrors' => $entries], '/project')->ignoreErrors);
    }

    public function testIgnoreErrorsFromParametersAreRelativeToTheRootDir(): void
    {
        $configuration = (new ConfigurationLoader())->fromParameters(['ignoreErrors' => [['identifier' => 'x', 'path' => 'Templates/A.html']]], '/project');
        $this->assertSame([['identifier' => 'x', 'path' => '/project/Templates/A.html']], $configuration->ignoreErrors);
    }
}
