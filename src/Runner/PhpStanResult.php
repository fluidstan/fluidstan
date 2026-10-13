<?php

declare(strict_types=1);

namespace FluidStan\Runner;

final readonly class PhpStanResult
{
    /**
     * @param list<PhpStanError> $errors
     * @param list<string> $generalErrors Errors not bound to a file (e.g. config problems)
     */
    public function __construct(
        public array $errors,
        public array $generalErrors,
        public string $rawOutput,
    ) {}
}
