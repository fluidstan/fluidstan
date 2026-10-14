<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Fluid\CollectingErrorHandler;
use PHPUnit\Framework\TestCase;

final class CollectingErrorHandlerTest extends TestCase
{
    public function testOnlyFluidsWrappedParseErrorsAreUnwrapped(): void
    {
        $wrapped = new \RuntimeException('Fluid parse error in template x, line 1 at character 1. Error: inner (error code 1). Template source chunk: ...', 0, new \RuntimeException('inner'));
        $this->assertSame('inner', CollectingErrorHandler::cleanMessage($wrapped));

        $other = new \RuntimeException('outer', 0, new \RuntimeException('inner'));
        $this->assertSame('outer', CollectingErrorHandler::cleanMessage($other));

        $this->assertSame('plain', CollectingErrorHandler::cleanMessage(new \RuntimeException('plain')));
    }

    public function testUndeclaredComponentArgumentsAreNamed(): void
    {
        $this->assertSame(
            'Component shop:atom.price has no argument "string", "nope".',
            CollectingErrorHandler::cleanMessage(new \RuntimeException('Invalid arguments supplied to component <shop:atom.price>: string, nope')),
        );
    }
}
