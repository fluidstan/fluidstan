<?php

declare(strict_types=1);

namespace FluidStan\Configuration;

use FluidStan\Transpiler\ViewHelperAssignments;
use Nette\Neon\Neon;

/**
 * Loads fluidstan.neon. Relative paths are resolved against the directory of the config file,
 * or against the working directory when no config file is used.
 */
final class ConfigurationLoader
{
    public const DEFAULT_FILE_NAME = 'fluidstan.neon';

    public const DEFAULT_NAMESPACES = [
        'f' => ['TYPO3Fluid\\Fluid\\ViewHelpers'],
    ];

    public function load(?string $configFile, string $workingDirectory): Configuration
    {
        $parameters = [];
        $rootDir = $workingDirectory;
        if ($configFile !== null) {
            if (!is_file($configFile)) {
                throw new ConfigurationException(\sprintf('Configuration file "%s" does not exist.', $configFile));
            }

            $rootDir = \dirname((string) realpath($configFile));
            $contents = file_get_contents($configFile);
            $decoded = Neon::decode($contents === false ? '' : $contents);
            if (!\is_array($decoded)) {
                throw new ConfigurationException(\sprintf('Configuration file "%s" must contain a "parameters" section.', $configFile));
            }

            $parameters = $this->stringMap($decoded['parameters'] ?? [], 'parameters');
        }

        return $this->fromParameters($parameters, $rootDir);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function fromParameters(array $parameters, string $rootDir): Configuration
    {
        $namespaces = self::DEFAULT_NAMESPACES;
        foreach ($this->stringMap($parameters['namespaces'] ?? [], 'namespaces') as $identifier => $value) {
            $namespaces[$identifier] = $this->stringList($value, 'namespaces.' . $identifier);
        }

        $phpstan = $parameters['phpstan'] ?? [];
        if (!\is_array($phpstan)) {
            throw new ConfigurationException('"phpstan" must be a map.');
        }

        if (\array_key_exists('level', $phpstan)) {
            throw new ConfigurationException('"phpstan.level" is not supported: FluidStan\'s checks implement Fluid\'s semantics and do not depend on PHPStan\'s rule level.');
        }

        $binary = $phpstan['binary'] ?? null;
        $tmpDir = $parameters['tmpDir'] ?? '.cache/fluidstan';
        $environment = $parameters['environment'] ?? null;
        if ($environment !== null && !\is_string($environment)) {
            throw new ConfigurationException('"environment" must be a class name.');
        }

        $viewHelperAssignments = [];
        foreach ($this->stringMap($parameters['viewHelperAssignments'] ?? [], 'viewHelperAssignments') as $class => $variables) {
            $viewHelperAssignments[$class] = [];
            foreach ($this->stringMap($variables, 'viewHelperAssignments.' . $class) as $name => $assignment) {
                // A type, or a map with the type and a scope: "children" for a variable the children see only,
                // "removed" when it is gone after the tag
                $type = \is_array($assignment) ? ($assignment['type'] ?? null) : $assignment;
                $scope = \is_array($assignment) ? ($assignment['scope'] ?? ViewHelperAssignments::SCOPE_TEMPLATE) : ViewHelperAssignments::SCOPE_TEMPLATE;
                if (!\is_string($type) || !\in_array($scope, [ViewHelperAssignments::SCOPE_TEMPLATE, ViewHelperAssignments::SCOPE_CHILDREN, ViewHelperAssignments::SCOPE_REMOVED], true)) {
                    throw new ConfigurationException(\sprintf('"viewHelperAssignments.%s.%s" must be a PHPDoc type, or a map with "type" and a "scope" of "template", "children" or "removed".', $class, $name));
                }

                $viewHelperAssignments[$class][$name] = ['type' => $type, 'scope' => $scope];
            }
        }

        $strict = $parameters['strict'] ?? false;
        if (!\is_bool($strict)) {
            throw new ConfigurationException('"strict" must be true or false.');
        }

        return new Configuration(
            rootDir: $rootDir,
            paths: $this->resolvePaths($this->stringList($parameters['paths'] ?? [], 'paths'), $rootDir),
            suffixes: $this->stringList($parameters['suffixes'] ?? ['.html'], 'suffixes'),
            namespaces: $namespaces,
            bootstrapFiles: $this->resolvePaths($this->stringList($parameters['bootstrapFiles'] ?? [], 'bootstrapFiles'), $rootDir),
            phpstanBinary: \is_string($binary) ? $this->resolvePath($binary, $rootDir) : null,
            phpstanIncludes: $this->resolvePaths($this->stringList($phpstan['includes'] ?? [], 'phpstan.includes'), $rootDir),
            phpstanParameters: $this->stringMap($phpstan['parameters'] ?? [], 'phpstan.parameters'),
            tmpDir: $this->resolvePath(\is_string($tmpDir) ? $tmpDir : '.cache/fluidstan', $rootDir),
            environment: $environment,
            viewHelperAssignments: $viewHelperAssignments,
            strict: $strict,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $key): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            throw new ConfigurationException(\sprintf('"%s" must be a list of strings.', $key));
        }

        $result = [];
        foreach ($value as $item) {
            if (!\is_scalar($item)) {
                throw new ConfigurationException(\sprintf('"%s" must be a list of strings.', $key));
            }

            $result[] = (string) $item;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringMap(mixed $value, string $key): array
    {
        if (!\is_array($value)) {
            throw new ConfigurationException(\sprintf('"%s" must be a map.', $key));
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
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
