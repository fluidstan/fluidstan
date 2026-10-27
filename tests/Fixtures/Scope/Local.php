<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\Scope;

/**
 * The value a template assigns locally; only it has "fromLocal".
 */
final class Local
{
    public function getFromLocal(): string
    {
        return 'local';
    }
}
