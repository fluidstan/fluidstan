<?php

declare(strict_types=1);

namespace FluidStan\Tests\Integration;

use FluidStan\Analyser;
use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Tests\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Runs the whole pipeline, including PHPStan, on the fixture templates.
 */
final class AnalyserTest extends TestCase
{
    use TemporaryDirectory;

    public function testFixturesReportExpectedErrors(): void
    {
        $root = __DIR__ . '/../Fixtures';
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', $root)->withTmpDir($this->tmpDir);
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $analyser = new Analyser($configuration, $autoload, \dirname($autoload) . '/bin/phpstan');
        $result = $analyser->analyse();

        $actual = array_map(
            static fn(Diagnostic $d): string => \sprintf('%s:%s %s', basename($d->file), $d->line, $d->identifier),
            $result->diagnostics->all(),
        );
        $this->assertSame([
            // variables assigned by ViewHelpers are typed from the tag, the configuration or the code
            'Assigned.html:10 fluidstan.property.unresolvable',
            'Assigned.html:10 fluidstan.property.unresolvable',
            'Assigned.html:10 variable.undefined',
            'Assigned.html:13 fluidstan.property.unresolvable',
            'Assigned.html:13 fluidstan.property.unresolvable',
            'Assigned.html:13 fluidstan.property.unresolvable',
            'Assigned.html:14 fluidstan.property.unresolvable',
            'Assigned.html:14 fluidstan.property.unresolvable',
            // variables a ViewHelper gives its children only, in both forms Fluid uses, are gone after the tag
            'Assigned.html:16 fluidstan.property.unresolvable',
            'Assigned.html:16 fluidstan.property.unresolvable',
            'Assigned.html:17 variable.undefined',
            'Assigned.html:17 variable.undefined',
            // in a section, f:alias restores the variable too
            'Assigned.html:18 fluidstan.property.unresolvable',
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
            // in a condition on a property path and after == (PHPStan is cautious with loose equality);
            // a Countable is compared as a bool by Fluid, so its branches are never dead (lines 31-35)
            'Conditions.html:9 fluidstan.argument.type',
            'Conditions.html:11 fluidstan.argument.type',
            'Conditions.html:13 fluidstan.argument.type',
            'Conditions.html:14 fluidstan.argument.type',
            'Conditions.html:15 fluidstan.argument.type',
            'Conditions.html:15 fluidstan.argument.type',
            'Conditions.html:16 fluidstan.argument.type',
            'Conditions.html:17 fluidstan.argument.type',
            'Conditions.html:17 fluidstan.argument.type',
            'Conditions.html:18 fluidstan.argument.type',
            'Conditions.html:19 fluidstan.argument.type',
            'Conditions.html:20 fluidstan.argument.type',
            'Conditions.html:23 fluidstan.argument.type',
            'Conditions.html:24 fluidstan.argument.type',
            'Conditions.html:25 fluidstan.argument.type',
            'Conditions.html:26 fluidstan.argument.type',
            'Conditions.html:26 fluidstan.argument.type',
            'Conditions.html:27 fluidstan.argument.type',
            'Conditions.html:28 fluidstan.argument.type',
            'Conditions.html:30 fluidstan.argument.type',
            'Conditions.html:31 fluidstan.argument.type',
            'Conditions.html:32 fluidstan.argument.type',
            'Conditions.html:33 fluidstan.argument.type',
            'Conditions.html:34 fluidstan.argument.type',
            'Conditions.html:35 fluidstan.argument.type',
            'Plain.html:2 fluidstan.property.unresolvable',
            // variables for the children of f:alias, f:cycle and scoping ViewHelpers are restored
            // afterwards in a plain template and in a section too
            'Scoped.html:3 fluidstan.property.unresolvable',
            'Scoped.html:4 fluidstan.property.unresolvable',
            'Scoped.html:5 fluidstan.property.unresolvable',
            'Scoped.html:5 fluidstan.property.unresolvable',
            'Scoped.html:9 fluidstan.property.unresolvable',
        ], $actual);
        $this->assertSame(12, $result->templateCount);
    }

    /**
     * Strict mode reports what Fluid tolerates silently; the lenient run above does not.
     */
    public function testStrictModeReportsWhatFluidTolerates(): void
    {
        $root = __DIR__ . '/../Fixtures/Strict';
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', $root)
            ->withTmpDir(sys_get_temp_dir() . '/fluidstan-strict-' . getmypid());
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $result = (new Analyser($configuration, $autoload, \dirname($autoload) . '/bin/phpstan'))->analyse();

        $actual = array_map(
            static fn(Diagnostic $d): string => \sprintf('%s:%s %s', basename($d->file), $d->line, $d->identifier),
            $result->diagnostics->all(),
        );
        $this->assertSame([
            // a string to float, then a bool to string, a string to int and a bool to float, then a float to int
            'Arguments.html:12 fluidstan.argument.cast',
            'Arguments.html:13 fluidstan.argument.cast',
            'Arguments.html:13 fluidstan.argument.cast',
            'Arguments.html:13 fluidstan.argument.cast',
            'Arguments.html:14 fluidstan.argument.cast',
            // what Fluid rejects is still a type error, not a cast
            'Arguments.html:15 fluidstan.argument.type',
            'Arguments.html:15 fluidstan.argument.type',
            // a numeric string with a fractional part is truncated by the cast to int, "7" is not; both are fine as float
            'Arguments.html:16 fluidstan.argument.cast',
            // an integer string beyond PHP_INT_MAX is capped by the cast; "01" and "1e3" keep their value
            'Arguments.html:18 fluidstan.argument.cast',
            // an object that cannot be null is always true; nullable, string, int, Countable and array operands stay open
            'Conditions.html:12 if.alwaysTrue',
            'Conditions.html:19 booleanAnd.leftAlwaysTrue',
            'Conditions.html:20 booleanOr.leftAlwaysTrue',
            'Conditions.html:21 booleanNot.alwaysFalse',
            'Conditions.html:23 if.alwaysFalse',
            'Conditions.html:24 if.alwaysTrue',
            'Conditions.html:25 if.alwaysFalse',
            // scalars of different kinds compared loosely; === is PHPStan's business, equal kinds and null are fine
            'Conditions.html:26 fluidstan.comparison.loose',
            'Conditions.html:27 fluidstan.comparison.loose',
            'Conditions.html:28 fluidstan.comparison.loose',
            'Conditions.html:29 fluidstan.comparison.loose',
            'Conditions.html:30 staticMethod.impossibleType',
            'Conditions.html:33 fluidstan.property.nullable',
            'Conditions.html:34 fluidstan.comparison.loose',
            'Conditions.html:36 fluidstan.comparison.loose',
            'Conditions.html:37 fluidstan.comparison.loose',
            'Conditions.html:38 if.alwaysTrue',
            // paths through a nullable parent and a nullable variable; not inside f:if on the value, and not on a sure one
            'Nullable.html:7 fluidstan.property.nullable',
            'Nullable.html:7 fluidstan.property.nullable',
            'Nullable.html:8 fluidstan.property.nullable',
            'Nullable.html:10 fluidstan.property.nullable',
            // a dynamic segment on a nullable subject
            'Nullable.html:11 fluidstan.property.nullable',
            // a bool, a nullable bool and a bool narrowed to true
            'Output.html:6 fluidstan.output.bool',
            'Output.html:7 fluidstan.output.bool',
            'Output.html:8 fluidstan.output.bool',
        ], $actual);
        $this->assertSame(4, $result->templateCount);
    }
}
