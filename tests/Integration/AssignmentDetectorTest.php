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

        $this->assertSame($expected, $detector->detect($classes), "Fluid's own ViewHelpers and unknown classes are skipped");

        $cached = $this->readCache();
        $cached['assignments'] = [ScopedViewHelper::class => ['{as}' => ['type' => 'cached', 'scope' => 'removed']]];
        $this->writeCache($cached);
        $this->assertSame($cached['assignments'], $detector->detect($classes), 'the same files hit the cache');

        $withLoad = $expected;
        $withLoad[LoadViewHelper::class] = ['item' => ['type' => Item::class, 'scope' => 'template']];
        ksort($withLoad);
        $this->assertSame($withLoad, $this->detector()->detect([...$classes, LoadViewHelper::class]), 'another set of files runs the detection again');
        $this->assertSame([], $detector->detect([AliasViewHelper::class]), 'nothing to analyse');
    }

    public function testIgnoresAnUnreadableCache(): void
    {
        $this->writeCache(['key' => 'stale', 'assignments' => ['X' => ['y' => 'not a map']]]);
        $expected = [AssignViewHelper::class => ['{target}' => ['type' => 'string', 'scope' => 'template']]];
        $this->assertSame($expected, $this->detector()->detect([AssignViewHelper::class]));

        $cached = $this->readCache();
        $cached['assignments'] = ['X' => 'broken', AssignViewHelper::class => ['{target}' => ['type' => 'string'], 'other' => 'x']];
        $this->writeCache($cached);
        $this->assertSame([], $this->detector()->detect([AssignViewHelper::class]), 'malformed cached entries are dropped');
    }

    /**
     * @return array<string, mixed>
     */
    private function readCache(): array
    {
        $this->assertFileExists($this->cacheFile());
        $cached = json_decode((string) file_get_contents($this->cacheFile()), true);
        $this->assertIsArray($cached);

        /** @var array<string, mixed> $cached */
        return $cached;
    }

    /**
     * @param array<string, mixed> $cached
     */
    private function writeCache(array $cached): void
    {
        file_put_contents($this->cacheFile(), json_encode($cached));
    }

    private function cacheFile(): string
    {
        return $this->tmpDir . '/viewhelper-assignments.json';
    }

    private function detector(): AssignmentDetector
    {
        $root = __DIR__ . '/../Fixtures';
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', $root)->withTmpDir($this->tmpDir);
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        return new AssignmentDetector($configuration, $autoload, \dirname($autoload) . '/bin/phpstan');
    }
}
