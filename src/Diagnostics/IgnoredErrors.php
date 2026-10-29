<?php

declare(strict_types=1);

namespace FluidStan\Diagnostics;

use FluidStan\Configuration\Configuration;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\Ignore\IgnoredErrorHelper;
use PHPStan\File\FileHelper;

/**
 * Drops the diagnostics the configuration's ignoreErrors cover, with PHPStan's own matching: the
 * entries have PHPStan's shape, "count" is checked, and an entry that matches nothing is reported
 * unless reportUnmatchedIgnoredErrors is off or only single files are analysed.
 */
final readonly class IgnoredErrors
{
    public function __construct(private Configuration $configuration) {}

    /**
     * @param list<string> $analysedFiles the template files of this run
     * @param bool $onlyFiles whether the run was limited to single files rather than directories
     */
    public function apply(DiagnosticCollection $diagnostics, array $analysedFiles, bool $onlyFiles): DiagnosticCollection
    {
        if ($this->configuration->ignoreErrors === []) {
            return $diagnostics;
        }

        $helper = new IgnoredErrorHelper(
            new FileHelper($this->configuration->rootDir),
            $this->configuration->ignoreErrors,
            $this->configuration->reportUnmatchedIgnoredErrors,
        );
        $initialized = $helper->initialize();

        $result = new DiagnosticCollection();
        foreach ($initialized->getErrors() as $message) {
            $result->add(new Diagnostic('', null, $message, 'ignore.configuration'));
        }

        $errors = [];
        foreach ($diagnostics as $diagnostic) {
            // A diagnostic without a template, PHPStan failing for instance, is not up for ignoring
            if ($diagnostic->file === '') {
                $result->add($diagnostic);
                continue;
            }

            $errors[] = new Error($diagnostic->message, $diagnostic->file, $diagnostic->line, true, $diagnostic->file, null, $diagnostic->tip, null, null, $diagnostic->identifier);
        }

        $processed = $initialized->process($errors, $onlyFiles, $analysedFiles, false);
        foreach ($processed->getNotIgnoredErrors() as $error) {
            $result->add(new Diagnostic($error->getFilePath(), $error->getLine(), $error->getMessage(), $error->getIdentifier(), $error->getTip()));
        }

        foreach ($processed->getOtherIgnoreMessages() as $message) {
            $result->add(new Diagnostic('', null, $message, 'ignore.unmatched'));
        }

        return $result;
    }
}
