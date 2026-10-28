<?php

declare(strict_types=1);

namespace FluidStan\Tests\Integration;

use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Detection\AssignmentDetector;
use FluidStan\Tests\Fixtures\Model\Item;
use FluidStan\Tests\Fixtures\ViewHelpers\AssignViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\DetectedViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\LoadViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\ProviderViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\ScopedViewHelper;
use FluidStan\Tests\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\AliasViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\CountViewHelper;

/**
 * Runs PHPStan with the detection rule over the fixture ViewHelpers.
 */
final class AssignmentDetectorTest extends TestCase
{
    use TemporaryDirectory;

    public function testDetectsNamesTypesAndScopesAndCachesThem(): void
    {
        $detector = $this->detector();
        // f:alias is modelled by the transpiler and skipped; f:count is Fluid's but analysed like any other, and assigns nothing
        $classes = [AssignViewHelper::class, DetectedViewHelper::class, ScopedViewHelper::class, ProviderViewHelper::class, AliasViewHelper::class, CountViewHelper::class, 'Nope\\ViewHelper'];
        $expected = [
            AssignViewHelper::class => ['{target}' => ['type' => 'string', 'scope' => 'template']],
            DetectedViewHelper::class => [
                'detected' => ['type' => Item::class, 'scope' => 'template'],
                'count' => ['type' => '3', 'scope' => 'template'],
                'flags' => ['type' => "array{'a', 'b'}", 'scope' => 'template'],
                // assigned after an early return: may be missing
                '{as}' => ['type' => '(non-empty-string&uppercase-string)|null', 'scope' => 'template'],
            ],
            // a scoped provider: restored afterwards
            ProviderViewHelper::class => [
                'label' => ['type' => "'provided'", 'scope' => 'children'],
                '{as}' => ['type' => Item::class, 'scope' => 'children'],
            ],
            // add(), renderChildren(), remove(): gone afterwards
            ScopedViewHelper::class => ['{as}' => ['type' => 'mixed', 'scope' => 'removed']],
        ];

        $this->assertSame($expected, $detector->detect($classes), "modelled ViewHelpers and unknown classes are skipped, Fluid's others find nothing");

        [$cacheFile, $cached] = $this->readCache();
        // the parents' files are analysed as well, here Fluid's AbstractViewHelper
        $this->assertSame($this->filesOf([AssignViewHelper::class, DetectedViewHelper::class, ProviderViewHelper::class, ScopedViewHelper::class, AbstractViewHelper::class, CountViewHelper::class]), $cached['files'] ?? null);
        $cached['assignments'] = [ScopedViewHelper::class => ['{as}' => ['type' => 'cached', 'scope' => 'removed']]];
        $this->writeCache($cacheFile, $cached);
        $this->assertSame($cached['assignments'], $detector->detect($classes), 'the same files hit the cache');

        $withLoad = $expected;
        $withLoad[LoadViewHelper::class] = ['item' => ['type' => Item::class, 'scope' => 'template']];
        ksort($withLoad);
        $this->assertSame($withLoad, $this->detector()->detect([...$classes, LoadViewHelper::class]), 'another set of files runs the detection again');
        $this->assertCount(2, $this->cacheFiles(), 'each set of files has its own cache file');
        $this->assertSame([], $detector->detect([AliasViewHelper::class]), 'nothing to analyse');
    }

    public function testIgnoresAnUnreadableCache(): void
    {
        $expected = [AssignViewHelper::class => ['{target}' => ['type' => 'string', 'scope' => 'template']]];
        $this->assertSame($expected, $this->detector()->detect([AssignViewHelper::class]));

        [$cacheFile, $cached] = $this->readCache();
        $cached['assignments'] = ['X' => 'broken', AssignViewHelper::class => ['{target}' => ['type' => 'string'], 'other' => 'x']];
        $this->writeCache($cacheFile, $cached);
        $this->assertSame([], $this->detector()->detect([AssignViewHelper::class]), 'malformed cached entries are dropped');

        file_put_contents($cacheFile, '{"assignments": ');
        $this->assertSame($expected, $this->detector()->detect([AssignViewHelper::class]), 'a half-written file is analysed again');
        $this->assertCount(1, $this->cacheFiles());
    }

    /**
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        $files = glob($this->tmpDir . '/viewhelper-assignments-*.json');
        return $files === false ? [] : $files;
    }

    /**
     * The only cache file and its content
     *
     * @return array{string, array<string, mixed>}
     */
    private function readCache(): array
    {
        $files = $this->cacheFiles();
        $this->assertCount(1, $files);
        $cached = json_decode((string) file_get_contents($files[0]), true);
        $this->assertIsArray($cached);

        /** @var array<string, mixed> $cached */
        return [$files[0], $cached];
    }

    /**
     * @param array<string, mixed> $cached
     */
    private function writeCache(string $cacheFile, array $cached): void
    {
        file_put_contents($cacheFile, json_encode($cached));
    }

    /**
     * @param list<class-string> $classes
     * @return list<string>
     */
    private function filesOf(array $classes): array
    {
        $files = array_map(static fn(string $class): string => (string) (new \ReflectionClass($class))->getFileName(), $classes);
        sort($files);
        return $files;
    }

    private function detector(): AssignmentDetector
    {
        $root = __DIR__ . '/../Fixtures';
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', $root)->withTmpDir($this->tmpDir);
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        return new AssignmentDetector($configuration, $autoload, \dirname($autoload) . '/bin/phpstan');
    }
}
