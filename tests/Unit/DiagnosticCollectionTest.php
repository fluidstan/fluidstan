<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Diagnostics\DiagnosticCollection;
use PHPUnit\Framework\TestCase;

final class DiagnosticCollectionTest extends TestCase
{
    public function testSortsDeduplicatesAndGroups(): void
    {
        $collection = new DiagnosticCollection();
        $collection->add(new Diagnostic('/b.html', 7, 'late'));
        $collection->add(new Diagnostic('/a.html', 9, 'second', 'id.two'));
        $collection->add(new Diagnostic('/a.html', 3, 'first', 'id.one'));
        $collection->add(new Diagnostic('/a.html', 3, 'first', 'id.one'));

        $other = new DiagnosticCollection();
        $other->add(new Diagnostic('/a.html', null, 'no line'));

        $collection->merge($other);

        $this->assertCount(4, $collection);
        $this->assertSame(
            ['/a.html:', '/a.html:3', '/a.html:9', '/b.html:7'],
            array_map(static fn(Diagnostic $d): string => $d->file . ':' . $d->line, $collection->all()),
        );
        $this->assertSame(['/a.html', '/b.html'], array_keys($collection->byFile()));
        $this->assertCount(3, $collection->byFile()['/a.html'] ?? []);
        $this->assertSame(['no line', 'first', 'second', 'late'], array_map(static fn(Diagnostic $d): string => $d->message, iterator_to_array($collection)));
    }

    public function testMessagesLoseControlCharactersAndFileCanBeReplaced(): void
    {
        $diagnostic = new Diagnostic('/a.html', 1, "bad\x1b[31m text\x07", 'id', 'tip');
        $this->assertSame('bad[31m text', $diagnostic->message);

        $moved = $diagnostic->withFile('/b.html');
        $this->assertSame('/b.html', $moved->file);
        $this->assertSame([1, 'id', 'tip'], [$moved->line, $moved->identifier, $moved->tip]);
    }
}
