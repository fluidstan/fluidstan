<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use PHPStan\Type\Type;

final readonly class PropertyPathResolution
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public Type $type,
        public array $errors,
    ) {}
}
