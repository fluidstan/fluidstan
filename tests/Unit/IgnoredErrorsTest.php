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
 * The matching of PHPStan-shaped entries: paths, messages, identifiers, counts, the report of
 * entries that match nothing, and that diagnostics without a template are left alone.
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
            // the unmatched #never# entry is not reported: PHPStan stays quiet about those after an internal error
            $b . ':3 fluidstan.other',
        ], array_map(static fn(Diagnostic $d): string => \sprintf('%s:%s %s', $d->file, $d->line ?? 'null', $d->identifier), $remaining->all()));
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

        // after an internal error, as in PHPStan
        $failed = new DiagnosticCollection();
        $failed->merge($diagnostics);
        $failed->add(new Diagnostic('', null, 'PHPStan failed', 'phpstan.internal'));

        $afterInternal = (new IgnoredErrors((new ConfigurationLoader())->fromParameters($entries, $this->tmpDir)))->apply($failed, [$a], false);
        $this->assertSame(['phpstan.internal', null], array_map(static fn(Diagnostic $d): ?string => $d->identifier, $afterInternal->all()));

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
        $this->assertStringContainsString('expected to occur 4 times, but occurred 3 times', $fewer->all()[0]->message ?? '');

        $more = (new IgnoredErrors($configuration(2)))->apply($three, [$a], false);
        $this->assertSame(['ignore.count', 'x'], array_map(static fn(Diagnostic $d): ?string => $d->identifier, $more->all()), 'more occurrences than counted: the mismatch and the extra one are reported');
    }

    public function testPathsAreFilesDirectoriesOrPatterns(): void
    {
        mkdir($this->tmpDir . '/Partials/Deep', 0o777, true);
        $a = $this->tmpDir . '/A.html';
        $deep = $this->tmpDir . '/Partials/Deep/P.html';
        $other = $this->tmpDir . '/Other.fluid';
        touch($a);
        touch($deep);
        touch($other);

        $ignore = fn(array $entry, string $file): int => \count((new IgnoredErrors((new ConfigurationLoader())->fromParameters(['ignoreErrors' => [$entry], 'reportUnmatchedIgnoredErrors' => false], $this->tmpDir)))->apply($this->single($file, 'Message', 'x'), [$file], false));

        $this->assertSame(0, $ignore(['identifier' => 'x', 'path' => 'A.html'], $a), 'a file');
        $this->assertSame(1, $ignore(['identifier' => 'x', 'path' => 'A.html'], $other), 'another file');
        $this->assertSame(0, $ignore(['identifier' => 'x', 'path' => 'Partials'], $deep), 'a directory covers everything below it');
        $this->assertSame(0, $ignore(['identifier' => 'x', 'path' => 'Partials/'], $deep), 'with a trailing slash too');
        $this->assertSame(1, $ignore(['identifier' => 'x', 'path' => 'Partials'], $a), 'but nothing beside it');
        $this->assertSame(1, $ignore(['identifier' => 'x', 'path' => 'Part'], $deep), 'a directory is no string prefix');
        $this->assertSame(0, $ignore(['identifier' => 'x', 'path' => '*.fluid'], $other), 'a pattern, against the absolute path');
        $this->assertSame(1, $ignore(['identifier' => 'x', 'path' => '*.fluid'], $a));
        $this->assertSame(0, $ignore(['identifier' => 'x', 'path' => 'Partials/../A.html'], $a), 'dot segments are resolved');
        $this->assertSame(1, $ignore(['identifier' => 'x', 'path' => 'Missing.html'], $a), 'a path that does not exist matches nothing');
        $this->assertSame(0, $ignore(['identifier' => 'x', 'paths' => ['Missing.html', 'A.html']], $a), 'any of the paths');
        $this->assertSame(1, $ignore(['identifier' => 'y', 'path' => 'A.html'], $a), 'the identifier must match too');
    }

    public function testMessagesRawMessagesAndTheListForms(): void
    {
        $ignore = fn(array|string $entry): int => \count((new IgnoredErrors((new ConfigurationLoader())->fromParameters(['ignoreErrors' => [$entry], 'reportUnmatchedIgnoredErrors' => false], $this->tmpDir)))->apply($this->single('/t.html', 'Variable "x" is not defined.', 'variable.undefined'), ['/t.html'], false));

        $this->assertSame(0, $ignore('#not defined#'), 'a plain pattern');
        $this->assertSame(1, $ignore('#^not defined#'));
        $this->assertSame(0, $ignore(['rawMessage' => 'Variable "x" is not defined.']), 'a raw message is compared as it is');
        $this->assertSame(1, $ignore(['rawMessage' => 'Variable "x" is not defined']));
        $this->assertSame(0, $ignore(['messages' => ['#nope#', '#"x"#']]), 'any of the messages');
        $this->assertSame(0, $ignore(['rawMessages' => ['nope', 'Variable "x" is not defined.']]));
        $this->assertSame(0, $ignore(['identifiers' => ['nope', 'variable.undefined']]));
        $this->assertSame(1, $ignore(['messages' => ['#"x"#'], 'identifiers' => ['nope']]), 'combined lists: both must match');
        $this->assertSame(0, $ignore(['messages' => ['#"x"#'], 'identifiers' => ['nope', 'variable.undefined']]));
    }

    public function testUnmatchedPathsAndPatternsAreReported(): void
    {
        $a = $this->tmpDir . '/A.html';
        touch($a);
        $configuration = (new ConfigurationLoader())->fromParameters(['ignoreErrors' => [
            ['identifier' => 'x', 'paths' => ['B.html', 'C.html']],
            '#never#',
            ['rawMessage' => 'Never', 'identifier' => 'y'],
        ]], $this->tmpDir);

        $remaining = (new IgnoredErrors($configuration))->apply($this->single($a, 'Message', 'x'), [$a], false);
        $this->assertSame([
            'Ignored error x in ' . $this->tmpDir . '/B.html, ' . $this->tmpDir . '/C.html was not matched in reported errors.',
            'Ignored error #never# was not matched in reported errors.',
            'Ignored error "Never", y was not matched in reported errors.',
            'Message',
        ], array_map(static fn(Diagnostic $d): string => $d->message, $remaining->all()));
        $this->assertSame(['ignore.unmatched', 'ignore.unmatched', 'ignore.unmatched', 'x'], array_map(static fn(Diagnostic $d): ?string => $d->identifier, $remaining->all()));
    }

    public function testAnEmptyListMatchesNothing(): void
    {
        $diagnostics = $this->single('/t.html', 'Message', 'x');
        foreach (['identifiers', 'messages', 'rawMessages', 'paths'] as $key) {
            // next to a criterion that matches, which an empty list must not widen
            $entry = $key === 'identifiers' ? ['message' => '#Message#', $key => []] : ['identifier' => 'x', $key => []];
            $configuration = (new ConfigurationLoader())->fromParameters(['ignoreErrors' => [$entry], 'reportUnmatchedIgnoredErrors' => false], $this->tmpDir);
            $this->assertCount(1, (new IgnoredErrors($configuration))->apply($diagnostics, ['/t.html'], false), $key . ': []');
        }
    }

    public function testAnUnmatchedDirectoryEntryIsReportedForADirectoryRun(): void
    {
        mkdir($this->tmpDir . '/Partials');
        $a = $this->tmpDir . '/Partials/A.html';
        touch($a);
        $configuration = (new ConfigurationLoader())->fromParameters(['ignoreErrors' => [['identifier' => 'never', 'path' => 'Partials']]], $this->tmpDir);

        $remaining = (new IgnoredErrors($configuration))->apply($this->single($a, 'Kept', 'x'), [$a], false);
        $this->assertSame(['ignore.unmatched', 'x'], array_map(static fn(Diagnostic $d): ?string => $d->identifier, $remaining->all()));
        $this->assertSame('', $remaining->all()[0]->file ?? null, 'a directory is no template to report it on');
    }

    private function single(string $file, string $message, string $identifier): DiagnosticCollection
    {
        $diagnostics = new DiagnosticCollection();
        $diagnostics->add(new Diagnostic($file, 1, $message, $identifier));
        return $diagnostics;
    }

    public function testNothingConfiguredChangesNothing(): void
    {
        $diagnostics = new DiagnosticCollection();
        $diagnostics->add(new Diagnostic('/t.html', 1, 'Kept'));
        $this->assertSame($diagnostics, (new IgnoredErrors((new ConfigurationLoader())->fromParameters([], '/p')))->apply($diagnostics, ['/t.html'], false));
    }
}
