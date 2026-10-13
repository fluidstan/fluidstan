<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

use FluidStan\Diagnostics\DiagnosticCollection;

final readonly class GeneratedFile
{
    public const LINE_MARKER = '// fluid:';

    public function __construct(
        public string $templateFile,
        public string $code,
        public DiagnosticCollection $diagnostics,
    ) {}

    /**
     * Maps a line of the generated code back to the template line via the nearest marker above.
     */
    public static function templateLine(string $code, int $generatedLine): ?int
    {
        $lines = \array_slice(explode("\n", $code), 0, $generatedLine);
        foreach (array_reverse($lines) as $line) {
            if (preg_match('#' . preg_quote(self::LINE_MARKER, '#') . '(\d+)#', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
