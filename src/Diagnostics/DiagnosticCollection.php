<?php

declare(strict_types=1);

namespace FluidStan\Diagnostics;

/**
 * @implements \IteratorAggregate<int, Diagnostic>
 */
final class DiagnosticCollection implements \IteratorAggregate, \Countable
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    public function merge(self $other): void
    {
        foreach ($other as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
    }

    /**
     * Sorted by file and line, duplicates removed.
     *
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        /** @var array<string, Diagnostic> $unique */
        $unique = [];
        foreach ($this->diagnostics as $diagnostic) {
            $unique[$diagnostic->file . ':' . $diagnostic->line . ':' . $diagnostic->message] = $diagnostic;
        }

        $result = array_values($unique);
        usort($result, static fn(Diagnostic $a, Diagnostic $b): int => [$a->file, $a->line ?? 0] <=> [$b->file, $b->line ?? 0]);
        return $result;
    }

    /**
     * @return array<string, list<Diagnostic>>
     */
    public function byFile(): array
    {
        $result = [];
        foreach ($this->all() as $diagnostic) {
            $result[$diagnostic->file][] = $diagnostic;
        }

        return $result;
    }

    /**
     * @return \Generator<int, Diagnostic>
     */
    public function getIterator(): \Generator
    {
        yield from $this->all();
    }

    public function count(): int
    {
        return \count($this->all());
    }
}
