<?php

declare(strict_types=1);

namespace FluidStan\Runner;

use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Diagnostics\DiagnosticCollection;
use FluidStan\Transpiler\GeneratedFile;

/**
 * Maps PHPStan errors on generated code back to template files and lines, and rewrites
 * messages that talk about PHP constructs into Fluid terms.
 */
final class ResultMapper
{
    private const IGNORED_IDENTIFIERS = [
        'function.unused',
        'parameter.unused',
    ];

    /**
     * @param array<string, GeneratedFile> $generatedFiles generated path => file
     */
    public function map(PhpStanResult $result, array $generatedFiles): DiagnosticCollection
    {
        $diagnostics = new DiagnosticCollection();
        foreach ($result->errors as $error) {
            $generated = $generatedFiles[$error->file] ?? null;
            if ($generated === null) {
                $diagnostics->add(new Diagnostic($error->file, $error->line, $error->message, $error->identifier));
                continue;
            }

            if ($error->identifier !== null && \in_array($error->identifier, self::IGNORED_IDENTIFIERS, true)) {
                continue;
            }

            $line = $error->line === null ? null : GeneratedFile::templateLine($generated->code, $error->line);
            $diagnostics->add(new Diagnostic($generated->templateFile, $line, self::rewrite($error->message), $error->identifier));
        }

        foreach ($result->generalErrors as $message) {
            $diagnostics->add(new Diagnostic('', null, $message, 'phpstan.internal'));
        }

        return $diagnostics;
    }

    public static function rewrite(string $message): string
    {
        $rewrites = [
            '/^Undefined variable: \$(\w+)$/' => 'Variable "$1" is not defined.',
            '/^Variable \$(\w+) might not be defined\.$/' => 'Variable "$1" might not be defined.',
            '/^Variable \$(\w+) in PHPDoc tag @var does not exist\.$/' => 'Variable "$1" of @var annotation does not exist.',
            '/^Parameter \$(\w+) of function fluidstan_template_\w+\(\) has invalid type (.*)$/' => 'Argument "$1" has invalid type $2',
            '/^PHPDoc tag @param for parameter \$(\w+) contains unknown class (.*)$/' => 'Argument "$1" has unknown class $2',
            '/^PHPDoc tag @param for parameter \$(\w+) (.*)$/' => 'Type of argument "$1" $2',
            '/^PHPDoc tag @var for variable \$(\w+) contains unknown class (.*)$/' => 'Annotated type of "$1" contains unknown class $2',
            '/^PHPDoc tag @var for variable \$(\w+) (.*)$/' => 'Annotated type of "$1" $2',
            '/^Function fluidstan_template_\w+\(\) (.*)$/' => 'Template $1',
        ];
        foreach ($rewrites as $pattern => $replacement) {
            $rewritten = preg_replace($pattern, $replacement, $message);
            if ($rewritten !== null && $rewritten !== $message) {
                return $rewritten;
            }
        }

        return $message;
    }
}
