<?php

declare(strict_types=1);

namespace FluidStan\Configuration;

/**
 * @phpstan-type IgnoreError array{message?: string, messages?: list<string>, rawMessage?: string, rawMessages?: list<string>, identifier?: string, identifiers?: list<string>, path?: string, paths?: list<string>, count?: int, reportUnmatched?: bool}
 */
final readonly class Configuration
{
    /**
     * @param list<string> $paths Template files or directories, absolute
     * @param list<string> $suffixes File name suffixes that identify templates, e.g. ".html"
     * @param array<string, list<string>> $namespaces Fluid namespace identifier => PHP namespaces or resolver delegate classes
     * @param list<string> $bootstrapFiles Files required before analysis (autoloaders, TYPO3 bootstrap)
     * @param list<string> $phpstanIncludes Additional PHPStan config files included into the generated config
     * @param array<string, mixed> $phpstanParameters Additional PHPStan parameters merged into the generated config
     * @param string|null $environment Class implementing FluidStan\Environment\EnvironmentInterface
     * @param array<string, array<string, array{type: string, scope: string}>> $viewHelperAssignments ViewHelper class => variable name or {argument} => type and scope
     * @param bool|null $strict Whether what Fluid tolerates silently is reported too: casts, paths through null, constant conditions, loose comparisons, rendered booleans; null is off and asks for a decision
     * @param list<string|IgnoreError> $ignoreErrors Entries of "ignoreErrors" as PHPStan takes them, paths absolute
     * @param bool $reportUnmatchedIgnoredErrors Whether an ignoreErrors entry that matches nothing is reported, as PHPStan does
     */
    public function __construct(
        public string $rootDir,
        public array $paths,
        public array $suffixes,
        public array $namespaces,
        public array $bootstrapFiles,
        public ?string $phpstanBinary,
        public array $phpstanIncludes,
        public array $phpstanParameters,
        public string $tmpDir,
        public ?string $environment = null,
        public array $viewHelperAssignments = [],
        public ?bool $strict = null,
        public array $ignoreErrors = [],
        public bool $reportUnmatchedIgnoredErrors = true,
    ) {}

    /**
     * @param list<string> $paths
     */
    public function withPaths(array $paths): self
    {
        return new self($this->rootDir, $paths, $this->suffixes, $this->namespaces, $this->bootstrapFiles, $this->phpstanBinary, $this->phpstanIncludes, $this->phpstanParameters, $this->tmpDir, $this->environment, $this->viewHelperAssignments, $this->strict, $this->ignoreErrors, $this->reportUnmatchedIgnoredErrors);
    }

    public function withTmpDir(string $tmpDir): self
    {
        return new self($this->rootDir, $this->paths, $this->suffixes, $this->namespaces, $this->bootstrapFiles, $this->phpstanBinary, $this->phpstanIncludes, $this->phpstanParameters, $tmpDir, $this->environment, $this->viewHelperAssignments, $this->strict, $this->ignoreErrors, $this->reportUnmatchedIgnoredErrors);
    }

    /**
     * For generating a baseline: an entry that matches nothing is no news while the list is rewritten.
     */
    public function withoutUnmatchedIgnoredErrorsReport(): self
    {
        return new self($this->rootDir, $this->paths, $this->suffixes, $this->namespaces, $this->bootstrapFiles, $this->phpstanBinary, $this->phpstanIncludes, $this->phpstanParameters, $this->tmpDir, $this->environment, $this->viewHelperAssignments, $this->strict, $this->ignoreErrors, false);
    }
}
