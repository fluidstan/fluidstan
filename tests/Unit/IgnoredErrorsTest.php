<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\Configuration;
use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Diagnostics\DiagnosticCollection;
use FluidStan\Diagnostics\IgnoredErrors;
use FluidStan\Tests\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The matching is PHPStan's; what is checked here is the wiring: paths, counts, identifiers, the
 * report of entries that match nothing, and that diagnostics without a template are left alone.
 */
final class IgnoredErrorsTest extends TestCase
{
    use TemporaryDirectory;

    public function testIgnoresWithPhpStanSemantics(): void
    {
        // PHPStan matches paths of files that exist
        $a = $this->tmpDir . '/A.html';
        $b = $this->tmpDir . '/B.html';
        touch($a);
        touch($b);

        $configuration = (new ConfigurationLoader())->fromParameters(['ignoreErrors' => [
            ['message' => '#^Variable "x" is not defined\\.$#', 'path' => 'A.html', 'count' => 2],
            ['identifier' => 'fluidstan.output.bool'],
            '#plain#',
            ['message' => '#never#', 'path' => 'B.html'],
        ]], $this->tmpDir);

        $diagnostics = new DiagnosticCollection();
        $diagnostics->add(new Diagnostic($a, 1, 'Variable "x" is not defined.', 'variable.undefined'));
        $diagnostics->add(new Diagnostic($a, 2, 'Variable "x" is not defined.', 'variable.undefined'));
        $diagnostics->add(new Diagnostic($a, 3, 'Variable "x" is not defined.', 'variable.undefined'));
        $diagnostics->add(new Diagnostic($b, 1, 'Rendering bool prints "1" or nothing.', 'fluidstan.output.bool'));
        $diagnostics->add(new Diagnostic($b, 2, 'A plain pattern', 'fluidstan.other'));
        $diagnostics->add(new Diagnostic($b, 3, 'Kept', 'fluidstan.other'));
        $diagnostics->add(new Diagnostic('', null, 'PHPStan failed', 'phpstan.internal'));

        $remaining = (new IgnoredErrors($configuration))->apply($diagnostics, [$a, $b], false);

        $this->assertSame([
            ':null phpstan.internal',
            // a count of 2 against 3 occurrences: PHPStan ignores two, keeps the third and reports the mismatch
            $a . ':1 ignore.count',
            $a . ':3 variable.undefined',
            // an entry with a path that matches nothing is reported at that path
            $b . ':null ignore.unmatched',
            $b . ':3 fluidstan.other',
        ], array_map(static fn(Diagnostic $d): string => \sprintf('%s:%s %s', $d->file, $d->line ?? 'null', $d->identifier), $remaining->all()));
        $this->assertStringContainsString('#never#', implode("\n", array_map(static fn(Diagnostic $d): string => $d->message, $remaining->all())));
    }

    public function testUnmatchedEntriesAreNotReportedForSingleFilesOrWhenTurnedOff(): void
    {
        $a = $this->tmpDir . '/A.html';
        touch($a);
        $diagnostics = new DiagnosticCollection();
        $diagnostics->add(new Diagnostic($a, 1, 'Kept'));

        $entries = ['ignoreErrors' => [['message' => '#never#', 'path' => 'A.html']]];

        $onlyFiles = (new IgnoredErrors((new ConfigurationLoader())->fromParameters($entries, $this->tmpDir)))->apply($diagnostics, [$a], true);
        $this->assertCount(1, $onlyFiles);

        $turnedOff = (new IgnoredErrors((new ConfigurationLoader())->fromParameters($entries + ['reportUnmatchedIgnoredErrors' => false], $this->tmpDir)))->apply($diagnostics, [$a], false);
        $this->assertCount(1, $turnedOff);

        // the same per entry
        $perEntry = (new IgnoredErrors((new ConfigurationLoader())->fromParameters(['ignoreErrors' => [['message' => '#never#', 'path' => 'A.html', 'reportUnmatched' => false]]], $this->tmpDir)))->apply($diagnostics, [$a], false);
        $this->assertCount(1, $perEntry);

        $reported = (new IgnoredErrors((new ConfigurationLoader())->fromParameters($entries, $this->tmpDir)))->apply($diagnostics, [$a], false);
        $this->assertCount(2, $reported);
        $this->assertSame('ignore.unmatched', $reported->all()[0]->identifier ?? null);
    }

    public function testACountIsCheckedExactly(): void
    {
        $a = $this->tmpDir . '/A.html';
        touch($a);
        $three = new DiagnosticCollection();
        foreach ([1, 2, 3] as $line) {
            $three->add(new Diagnostic($a, $line, 'Repeated', 'x'));
        }

        $configuration = fn(int $count): Configuration => (new ConfigurationLoader())->fromParameters(['ignoreErrors' => [['message' => '#^Repeated$#', 'path' => 'A.html', 'count' => $count]]], $this->tmpDir);

        $this->assertCount(0, (new IgnoredErrors($configuration(3)))->apply($three, [$a], false), 'the count matches');

        $fewer = (new IgnoredErrors($configuration(4)))->apply($three, [$a], false);
        $this->assertSame(['ignore.count'], array_map(static fn(Diagnostic $d): ?string => $d->identifier, $fewer->all()), 'fewer occurrences than counted: the mismatch is reported, all three are ignored');
        $this->assertStringContainsString('expected to occur 4 times, but occurred only 3 times', $fewer->all()[0]->message ?? '');

        $more = (new IgnoredErrors($configuration(2)))->apply($three, [$a], false);
        $this->assertSame(['ignore.count', 'x'], array_map(static fn(Diagnostic $d): ?string => $d->identifier, $more->all()), 'more occurrences than counted: the mismatch and the extra one are reported');
    }

    public function testNothingConfiguredChangesNothing(): void
    {
        $diagnostics = new DiagnosticCollection();
        $diagnostics->add(new Diagnostic('/t.html', 1, 'Kept'));
        $this->assertSame($diagnostics, (new IgnoredErrors((new ConfigurationLoader())->fromParameters([], '/p')))->apply($diagnostics, ['/t.html'], false));
    }
}
