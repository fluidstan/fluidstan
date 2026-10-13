<?php

declare(strict_types=1);

namespace FluidStan\Tests\Integration;

use FluidStan\Analyser;
use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Diagnostics\Diagnostic;
use PHPUnit\Framework\TestCase;

/**
 * Runs the whole pipeline, including PHPStan, on the fixture templates.
 */
final class AnalyserTest extends TestCase
{
    public function testFixturesReportExpectedErrors(): void
    {
        $root = __DIR__ . '/../Fixtures';
        // A temporary directory keeps generated code out of the source tree, also under mutation testing
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', $root)
            ->withTmpDir(sys_get_temp_dir() . '/fluidstan-test-' . getmypid());
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $analyser = new Analyser($configuration, $autoload, \dirname($autoload) . '/bin/phpstan');
        $result = $analyser->analyse();

        $actual = array_map(
            static fn(Diagnostic $d): string => \sprintf('%s:%s %s', basename($d->file), $d->line, $d->identifier),
            $result->diagnostics->all(),
        );
        $this->assertSame([
            'Price.html:8 fluidstan.property.unresolvable',
            'Price.html:10 fluidstan.output.object',
            'Price.html:11 fluidstan.property.unresolvable',
            'Price.html:12 variable.undefined',
            'Price.html:13 fluidstan.property.unresolvable',
            'Price.html:14 fluidstan.for.notIterable',
            'Price.html:16 fluidstan.argument.type',
            'Card.html:7 fluidstan.argument.type',
            'Card.html:8 fluidstan.argument.type',
            'Card.html:9 fluid.component.unknownSlot',
            'Card.html:14 fluidstan.property.unresolvable',
            'Card.html:16 fluid.render.unknownSection',
            'Plain.html:2 fluidstan.property.unresolvable',
        ], $actual);
        $this->assertSame(4, $result->templateCount);
    }
}
