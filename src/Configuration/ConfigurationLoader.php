<?php

declare(strict_types=1);

namespace FluidStan\Configuration;

use FluidStan\Transpiler\ViewHelperAssignments;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;
use Nette\Schema\ValidationException;

/**
 * @phpstan-import-type IgnoreError from Configuration
 * @phpstan-type Parameters array{paths: list<string>, suffixes: string|list<string>, namespaces: array<string, string|list<string>>, bootstrapFiles: list<string>, tmpDir: string, environment: string|null, strict: bool|null, viewHelperAssignments: array<string, array<string, string|array{type: string, scope: string}>>, ignoreErrors: list<string|IgnoreError>, reportUnmatchedIgnoredErrors: bool, phpstan: array{binary: string|null, includes: list<string>, parameters: array<string, mixed>}}
 *
 * Loads fluidstan.neon. Relative paths are resolved against the directory of the config file,
 * or against the working directory when no config file is used. A file may include others, a
 * baseline for instance; ConfigFileLoader reads them as PHPStan reads its own.
 */
final class ConfigurationLoader
{
    public const DEFAULT_FILE_NAME = 'fluidstan.neon';

    public const DEFAULT_NAMESPACES = [
        'f' => ['TYPO3Fluid\\Fluid\\ViewHelpers'],
    ];

    /**
     * @param string|null $generateBaselineFile the baseline about to be written, whose include is skipped so that it does not hide what it records
     */
    public function load(?string $configFile, string $workingDirectory, ?string $generateBaselineFile = null): Configuration
    {
        $parameters = [];
        $rootDir = $workingDirectory;
        if ($configFile !== null) {
            if (!is_file($configFile)) {
                throw new ConfigurationException(\sprintf('Configuration file "%s" does not exist.', $configFile));
            }

            // PHPStan's loader resolves the paths of included files against the main file's path as given;
            // a relative one would turn "../" entries into siblings of the configuration directory
            $configFile = (string) realpath($configFile);
            $rootDir = \dirname($configFile);
            $parameters = $this->decode($configFile, $generateBaselineFile);
        }

        return $this->fromParameters($parameters, $rootDir);
    }

    /**
     * The configuration file with its includes, see ConfigFileLoader. Relative paths other than those
     * of ignoreErrors entries stay as written and are resolved against the main file's directory.
     *
     * @return array<string, mixed>
     */
    private function decode(string $configFile, ?string $generateBaselineFile): array
    {
        try {
            $decoded = (new ConfigFileLoader($generateBaselineFile))->load($configFile);
        } catch (\Throwable $throwable) {
            throw new ConfigurationException(\sprintf('Configuration file "%s" could not be loaded: %s', $configFile, $throwable->getMessage()), 0, $throwable);
        }

        $parameters = $decoded['parameters'] ?? [];
        if (!\is_array($parameters)) {
            throw new ConfigurationException(\sprintf('Configuration file "%s" must contain a "parameters" section.', $configFile));
        }

        /** @var array<string, mixed> $parameters */
        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function fromParameters(array $parameters, string $rootDir): Configuration
    {
        if (\is_array($parameters['phpstan'] ?? null) && \array_key_exists('level', $parameters['phpstan'])) {
            throw new ConfigurationException('"phpstan.level" is not supported: FluidStan\'s checks implement Fluid\'s semantics and do not depend on PHPStan\'s rule level.');
        }

        try {
            /** @var Parameters $normalized */
            $normalized = (new Processor())->process($this->schema(), $parameters);
        } catch (ValidationException $validationException) {
            throw new ConfigurationException(implode("\n", $validationException->getMessages()), 0, $validationException);
        }

        $namespaces = self::DEFAULT_NAMESPACES;
        foreach ($normalized['namespaces'] as $identifier => $value) {
            $namespaces[$identifier] = \is_string($value) ? [$value] : $value;
        }

        $viewHelperAssignments = [];
        foreach ($normalized['viewHelperAssignments'] as $class => $variables) {
            foreach ($variables as $name => $assignment) {
                // A type, or a map with the type and a scope
                $viewHelperAssignments[$class][$name] = \is_string($assignment) ? ['type' => $assignment, 'scope' => ViewHelperAssignments::SCOPE_TEMPLATE] : $assignment;
            }
        }

        $ignoreErrors = [];
        foreach ($normalized['ignoreErrors'] as $entry) {
            if (\is_array($entry)) {
                if (isset($entry['path'])) {
                    $entry['path'] = $this->resolvePath($entry['path'], $rootDir);
                }

                if (isset($entry['paths'])) {
                    $entry['paths'] = $this->resolvePaths($entry['paths'], $rootDir);
                }
            }

            $ignoreErrors[] = $entry;
        }

        return new Configuration(
            rootDir: $rootDir,
            paths: $this->resolvePaths($normalized['paths'], $rootDir),
            suffixes: \is_string($normalized['suffixes']) ? [$normalized['suffixes']] : $normalized['suffixes'],
            namespaces: $namespaces,
            bootstrapFiles: $this->resolvePaths($normalized['bootstrapFiles'], $rootDir),
            phpstanBinary: $normalized['phpstan']['binary'] === null ? null : $this->resolvePath($normalized['phpstan']['binary'], $rootDir),
            phpstanIncludes: $this->resolvePaths($normalized['phpstan']['includes'], $rootDir),
            phpstanParameters: $normalized['phpstan']['parameters'],
            tmpDir: $this->resolvePath($normalized['tmpDir'], $rootDir),
            environment: $normalized['environment'],
            viewHelperAssignments: $viewHelperAssignments,
            strict: $normalized['strict'],
            ignoreErrors: $ignoreErrors,
            reportUnmatchedIgnoredErrors: $normalized['reportUnmatchedIgnoredErrors'],
        );
    }

    /**
     * The parameters as a Nette Schema, the way PHPStan declares its own in parametersSchema: shapes,
     * defaults and the rejection of unknown keys come from the schema, the messages from Nette.
     */
    private function schema(): Schema
    {
        $stringOrList = static fn(): Schema => Expect::anyOf(Expect::string(), Expect::listOf('string'));
        $pattern = static fn(): Schema => Expect::string()->assert(static fn(mixed $pattern): bool => \is_string($pattern) && @preg_match($pattern, '') !== false, 'a regular expression with delimiters');

        // PHPStan's ignoreErrors entry: a pattern, or a map naming the error by message(s), raw message(s)
        // or identifier(s), optionally bound to a path or paths and to a count
        $ignoreError = Expect::structure([
            'message' => $pattern(),
            'messages' => Expect::listOf($pattern()),
            'rawMessage' => Expect::string(),
            'rawMessages' => Expect::listOf('string'),
            'identifier' => Expect::string(),
            'identifiers' => Expect::listOf('string'),
            'path' => Expect::string(),
            'paths' => Expect::listOf('string'),
            'count' => Expect::int()->min(1),
            'reportUnmatched' => Expect::bool(),
        ])->skipDefaults()->castTo('array')
            ->assert(static fn(mixed $entry): bool => \is_array($entry) && array_intersect_key($entry, array_flip(['message', 'messages', 'rawMessage', 'rawMessages', 'identifier', 'identifiers'])) !== [], 'a message, rawMessage or identifier')
            ->assert(static fn(mixed $entry): bool => \is_array($entry) && !isset($entry['path'], $entry['paths']), 'either path or paths');

        return Expect::structure([
            'paths' => Expect::listOf('string')->default([]),
            'suffixes' => $stringOrList()->default(['.html']),
            'namespaces' => Expect::arrayOf($stringOrList(), 'string')->default([]),
            'bootstrapFiles' => Expect::listOf('string')->default([]),
            'tmpDir' => Expect::string('.cache/fluidstan'),
            'environment' => Expect::string()->nullable(),
            'strict' => Expect::bool()->nullable(),
            'viewHelperAssignments' => Expect::arrayOf(Expect::arrayOf(Expect::anyOf(
                Expect::string(),
                Expect::structure([
                    'type' => Expect::string()->required(),
                    'scope' => Expect::anyOf(ViewHelperAssignments::SCOPE_TEMPLATE, ViewHelperAssignments::SCOPE_CHILDREN, ViewHelperAssignments::SCOPE_REMOVED)->default(ViewHelperAssignments::SCOPE_TEMPLATE),
                ])->castTo('array'),
            ), 'string'), 'string')->default([]),
            'ignoreErrors' => Expect::listOf(Expect::anyOf($pattern(), $ignoreError))->default([]),
            'reportUnmatchedIgnoredErrors' => Expect::bool(true),
            'phpstan' => Expect::structure([
                'binary' => Expect::string()->nullable(),
                'includes' => Expect::listOf('string')->default([]),
                'parameters' => Expect::arrayOf('mixed', 'string')->default([]),
            ])->castTo('array'),
        ])->castTo('array');
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function resolvePaths(array $paths, string $rootDir): array
    {
        return array_map(fn(string $path): string => $this->resolvePath($path, $rootDir), $paths);
    }

    public function resolvePath(string $path, string $rootDir): string
    {
        // Absolute paths, Windows drive letters and framework notations such as "EXT:" stay untouched
        if (str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1 || preg_match('/^[A-Za-z]{2,}:/', $path) === 1) {
            return $path;
        }

        return rtrim($rootDir, '/') . '/' . $path;
    }
}
