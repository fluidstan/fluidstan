<?php

declare(strict_types=1);

namespace FluidStan\Diagnostics;

use FluidStan\Configuration\Configuration;

/**
 * Drops the diagnostics the configuration's ignoreErrors cover. The entries have PHPStan's shape; the
 * first entry that matches ignores a diagnostic. With "count", exactly that many occurrences are
 * ignored, a different number is reported as ignore.count. An entry that matches nothing is reported
 * as ignore.unmatched, unless reportUnmatched is off, PHPStan failed, or only single files were
 * analysed and the entry is not bound to one of them.
 *
 * @phpstan-import-type IgnoreError from Configuration
 */
final readonly class IgnoredErrors
{
    public function __construct(private Configuration $configuration) {}

    /**
     * @param list<string> $analysedFiles the template files of this run
     * @param bool $onlyFiles whether the run was limited to single files rather than directories
     */
    public function apply(DiagnosticCollection $diagnostics, array $analysedFiles, bool $onlyFiles): DiagnosticCollection
    {
        $entries = $this->configuration->ignoreErrors;
        if ($entries === []) {
            return $diagnostics;
        }

        $result = new DiagnosticCollection();
        /** @var array<int, array{count: int, first: Diagnostic}> $matches */
        $matches = [];
        $hasInternalErrors = false;
        foreach ($diagnostics as $diagnostic) {
            // A diagnostic without a template, PHPStan failing for instance, is not up for ignoring
            if ($diagnostic->file === '') {
                $result->add($diagnostic);
                $hasInternalErrors = true;
                continue;
            }

            foreach ($entries as $index => $entry) {
                if (!$this->matches($entry, $diagnostic)) {
                    continue;
                }

                $matches[$index] ??= ['count' => 0, 'first' => $diagnostic];
                ++$matches[$index]['count'];
                // Beyond its count, an entry leaves the diagnostic to the next one
                if (!\is_array($entry) || !isset($entry['count']) || $matches[$index]['count'] <= $entry['count']) {
                    continue 2;
                }
            }

            $result->add($diagnostic);
        }

        $analysed = array_flip(array_filter(array_map(realpath(...), $analysedFiles), \is_string(...)));
        foreach ($entries as $index => $entry) {
            $match = $matches[$index] ?? null;
            $count = $match['count'] ?? 0;
            $expected = \is_array($entry) ? $entry['count'] ?? null : null;
            // Bound to a template: a path that is a file, not a directory or a pattern
            $boundTo = \is_array($entry) && isset($entry['path']) && is_file($entry['path']) ? realpath($entry['path']) : false;
            // After an internal error, entries that match nothing are no news
            $report = !$hasInternalErrors && ((\is_array($entry) ? $entry['reportUnmatched'] ?? null : null) ?? $this->configuration->reportUnmatchedIgnoredErrors);

            if ($match !== null && $expected !== null && ($count > $expected || ($count < $expected && $report && ($boundTo !== false || !$onlyFiles)))) {
                $result->add(new Diagnostic($match['first']->file, $match['first']->line, \sprintf('Ignored error %s is expected to occur %d times, but occurred %d times.', $this->describe($entry), $expected, $count), 'ignore.count'));
            } elseif ($count === 0 && $report && !$onlyFiles && ($boundTo === false || isset($analysed[$boundTo]))) {
                $result->add(new Diagnostic($boundTo !== false ? $boundTo : '', null, \sprintf('Ignored error %s was not matched in reported errors.', $this->describe($entry)), 'ignore.unmatched'));
            }
        }

        return $result;
    }

    /**
     * @param string|IgnoreError $entry
     */
    private function matches(string|array $entry, Diagnostic $diagnostic): bool
    {
        if (\is_string($entry)) {
            return preg_match($entry, $diagnostic->message) === 1;
        }

        // Each criterion that is given must match, by one of its values; an empty list matches nothing
        $anyOf = fn(string $key, callable $test): bool => !isset($entry[$key]) && !isset($entry[$key . 's']) || array_filter($this->values($entry, $key), $test) !== [];

        return $anyOf('identifier', static fn(string $identifier): bool => $identifier === $diagnostic->identifier)
            && $anyOf('message', static fn(string $pattern): bool => preg_match($pattern, $diagnostic->message) === 1)
            && $anyOf('rawMessage', static fn(string $message): bool => $message === $diagnostic->message)
            && $anyOf('path', static fn(string $path): bool => self::matchesPath($path, $diagnostic->file));
    }

    /**
     * The values of a criterion, given singly ("path") or as a list ("paths").
     *
     * @param IgnoreError $entry
     * @param 'identifier'|'message'|'rawMessage'|'path' $key
     * @return list<string>
     */
    private function values(array $entry, string $key): array
    {
        return [...isset($entry[$key]) ? [$entry[$key]] : [], ...$entry[$key . 's'] ?? []];
    }

    /**
     * A file, a directory with everything below it, or an fnmatch() pattern.
     */
    private static function matchesPath(string $path, string $file): bool
    {
        $file = realpath($file);
        if ($file === false) {
            return false;
        }

        $real = realpath($path);
        if ($real === false) {
            return preg_match('~[*?[\]]~', $path) === 1 && fnmatch($path, $file);
        }

        return $file === $real || str_starts_with($file, rtrim($real, '/') . '/');
    }

    /**
     * @param string|IgnoreError $entry
     */
    private function describe(string|array $entry): string
    {
        if (\is_string($entry)) {
            return $entry;
        }

        $what = implode(', ', [
            ...$this->values($entry, 'message'),
            ...array_map(static fn(string $message): string => '"' . $message . '"', $this->values($entry, 'rawMessage')),
            ...$this->values($entry, 'identifier'),
        ]);
        $paths = $this->values($entry, 'path');

        return $paths === [] ? $what : \sprintf('%s in %s', $what, implode(', ', $paths));
    }
}
