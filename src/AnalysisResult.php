<?php

declare(strict_types=1);

namespace FluidStan;

use FluidStan\Diagnostics\DiagnosticCollection;

final readonly class AnalysisResult
{
    public function __construct(
        public DiagnosticCollection $diagnostics,
        public int $templateCount,
        public string $generatedDir,
    ) {}
}
