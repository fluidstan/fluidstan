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
            // a component without arguments still has its collection's variables
            'Logo.html:2 fluidstan.property.unresolvable',
            'Logo.html:2 variable.undefined',
            // variables from getAdditionalVariables(), typed after the method's return statements
            'Meta.html:6 fluidstan.property.unresolvable',
            'Meta.html:7 fluidstan.property.unresolvable',
            'Meta.html:8 fluidstan.property.unresolvable',
            'Meta.html:10 fluidstan.for.notIterable',
            'Meta.html:12 variable.undefined',
            // f:variable reassigned from the collection's value: int|float afterwards
            'Meta.html:15 fluidstan.property.unresolvable',
            'Meta.html:15 fluidstan.property.unresolvable',
            // assigned in f:then only: the else branch reads the collection's value ...
            'Meta.html:17 fluidstan.property.unresolvable',
            // ... and afterwards it is either
            'Meta.html:18 fluidstan.property.unresolvable',
            'Meta.html:18 fluidstan.property.unresolvable',
            // loop variables shadow the collection inside the loop and are restored afterwards
            'Meta.html:19 fluidstan.property.unresolvable',
            'Meta.html:19 fluidstan.property.unresolvable',
            'Meta.html:20 variable.undefined',
            // a conditional local the collection does not provide is null on the other path, not an error
            'Meta.html:22 fluidstan.property.unresolvable',
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
            // the declared @return shape wins over what the method body returns
            'Note.html:4 fluidstan.property.unresolvable',
            'Note.html:4 fluidstan.property.unresolvable',
            'Note.html:4 variable.undefined',
            // objects and lists passed to scalar or class typed arguments
            'Arguments.html:7 fluidstan.argument.type',
            'Arguments.html:7 fluidstan.argument.type',
            'Arguments.html:7 fluidstan.argument.type',
            'Arguments.html:7 fluidstan.argument.type',
            // an undeclared argument, reported without losing the tag
            'Arguments.html:8 fluid.viewHelper',
            // every missing required argument, not just the first
            'Arguments.html:9 fluid.parser',
            'Arguments.html:9 fluid.parser',
            'Arguments.html:9 fluid.parser',
            'Arguments.html:9 fluid.parser',
            'Arguments.html:9 fluid.parser',
            // a nullable value is not null inside f:if, in && chains, in f:else if, after === and
            // after a size comparison; it stays nullable in the else branch, after ||, behind !,
            // in a condition on a property path and after == (PHPStan is cautious with loose equality)
            'Conditions.html:8 fluidstan.argument.type',
            'Conditions.html:10 fluidstan.argument.type',
            'Conditions.html:12 fluidstan.argument.type',
            'Conditions.html:13 fluidstan.argument.type',
            'Conditions.html:14 fluidstan.argument.type',
            'Conditions.html:14 fluidstan.argument.type',
            'Conditions.html:15 fluidstan.argument.type',
            'Conditions.html:16 fluidstan.argument.type',
            'Conditions.html:16 fluidstan.argument.type',
            'Conditions.html:17 fluidstan.argument.type',
            'Conditions.html:18 fluidstan.argument.type',
            'Conditions.html:19 fluidstan.argument.type',
            'Conditions.html:22 fluidstan.argument.type',
            'Conditions.html:23 fluidstan.argument.type',
            'Conditions.html:24 fluidstan.argument.type',
            'Conditions.html:25 fluidstan.argument.type',
            'Conditions.html:25 fluidstan.argument.type',
            'Conditions.html:26 fluidstan.argument.type',
            'Conditions.html:27 fluidstan.argument.type',
            'Conditions.html:29 fluidstan.argument.type',
            'Plain.html:2 fluidstan.property.unresolvable',
        ], $actual);
        $this->assertSame(10, $result->templateCount);
    }
}
