<?php

declare(strict_types=1);

namespace FluidStan\Runner;

final readonly class PhpStanError
{
    public function __construct(
        public string $file,
        public ?int $line,
        public string $message,
        public ?string $identifier,
    ) {}
}
