<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Transpiler\Annotations;
use PHPUnit\Framework\TestCase;

final class AnnotationsTest extends TestCase
{
    public function testParsesVarAnnotationsWithLines(): void
    {
        $source = "<f:comment>\n@var App\\Item item\n@var list<App\\Item> \$items\n</f:comment>\n<!-- @var int|null count -->";
        $this->assertSame([
            'item' => ['type' => 'App\\Item', 'line' => 2],
            'items' => ['type' => 'list<App\\Item>', 'line' => 3],
            'count' => ['type' => 'int|null', 'line' => 5],
        ], (new Annotations())->parse($source));
    }
}
