<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\Scope;

/**
 * The value the collection provides for a variable; only it has "fromCollection".
 */
final class Provided
{
    public function getFromCollection(): string
    {
        return 'collection';
    }
}
