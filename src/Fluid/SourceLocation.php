<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

/**
 * Position in a template file. Fluid 5 has TYPO3Fluid\Fluid\Core\Parser\TemplateLocation for the
 * same purpose; this class keeps FluidStan compatible with Fluid 4.
 */
final readonly class SourceLocation
{
    public function __construct(
        public string $file,
        public int $line,
        public int $column,
    ) {}
}
