<?php

declare(strict_types=1);

namespace FluidStan\Diagnostics;

use Nette\Neon\Neon;
use PHPStan\File\ParentDirectoryRelativePathHelper;

/**
 * Writes the current diagnostics as a baseline: a configuration file with an ignoreErrors entry per
 * message and template, in PHPStan's format, with paths relative to the baseline file.
 */
final class BaselineWriter
{
    /**
     * @return int the number of errors the baseline covers
     */
    public function write(DiagnosticCollection $diagnostics, string $baselineFile): int
    {
        // Paths relative to the baseline, with ".." where needed, as PHPStan writes its own baselines
        $relative = new ParentDirectoryRelativePathHelper(\dirname($baselineFile));
        $entries = [];
        $total = 0;
        foreach ($diagnostics as $diagnostic) {
            // Without a template and a line there is nothing to match against
            if ($diagnostic->file === '' || $diagnostic->line === null) {
                continue;
            }

            $key = $diagnostic->file . "\0" . $diagnostic->message . "\0" . $diagnostic->identifier;
            $entries[$key] ??= [
                'message' => '#^' . preg_quote($diagnostic->message, '#') . '$#',
                'identifier' => $diagnostic->identifier,
                'count' => 0,
                'path' => $relative->getRelativePath($diagnostic->file),
            ];
            ++$entries[$key]['count'];
            ++$total;
        }

        $entries = array_values($entries);
        usort($entries, static fn(array $a, array $b): int => [$a['path'], $a['message']] <=> [$b['path'], $b['message']]);
        $entries = array_map(static fn(array $entry): array => $entry['identifier'] === null ? array_diff_key($entry, ['identifier' => true]) : $entry, $entries);

        $directory = \dirname($baselineFile);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $directory));
        }

        if (@file_put_contents($baselineFile, rtrim(Neon::encode(['parameters' => ['ignoreErrors' => $entries]], true)) . "\n") === false) {
            throw new \RuntimeException(\sprintf('The baseline "%s" could not be written.', $baselineFile));
        }

        return $total;
    }
}
