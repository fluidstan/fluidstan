<?php

declare(strict_types=1);

namespace FluidStan\Detection;

use FluidStan\Configuration\Configuration;
use FluidStan\PhpStan\Rules\ViewHelperAssignmentRule;
use FluidStan\Runner\PhpStanRunner;
use FluidStan\Transpiler\ViewHelperAssignments;
use Nette\Neon\Neon;

/**
 * Finds out which template variables the project's ViewHelpers assign by running PHPStan over
 * their classes with the detection rule, before the templates are transpiled. ViewHelpers of Fluid
 * itself are left out: the transpiler models the assigning ones. The result is cached by the files
 * involved, so the pass only runs when a ViewHelper changed.
 */
final readonly class AssignmentDetector
{
    /** Changes whenever the cached data changes shape */
    private const FORMAT = '3';

    public function __construct(
        private Configuration $configuration,
        private ?string $autoloadFile,
        private string $phpstanBinary,
    ) {}

    /**
     * @param list<string> $classes
     * @return array<string, array<string, array{type: string, scope: string}>> declaring class => variable name or {argument} => assignment
     *
     * @phpstan-impure the result comes from the cache file when the ViewHelper files are unchanged
     */
    public function detect(array $classes): array
    {
        $files = $this->classFiles($classes);
        if ($files === []) {
            return [];
        }

        $cacheFile = $this->configuration->tmpDir . '/viewhelper-assignments.json';
        $key = sha1(self::FORMAT . "\n" . implode("\n", array_map(static fn(string $file): string => $file . ':' . filemtime($file) . ':' . filesize($file), $files)));
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (\is_array($cached) && ($cached['key'] ?? null) === $key && \is_array($cached['assignments'] ?? null)) {
            return $this->stringMaps($cached['assignments']);
        }

        $assignments = $this->run($files);
        file_put_contents($cacheFile, json_encode(['key' => $key, 'assignments' => $assignments], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $assignments;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, array<string, array{type: string, scope: string}>>
     */
    private function stringMaps(array $value): array
    {
        $maps = [];
        foreach ($value as $class => $variables) {
            if (!\is_array($variables)) {
                continue;
            }

            foreach ($variables as $name => $assignment) {
                if (\is_array($assignment) && \is_string($assignment['type'] ?? null) && \is_string($assignment['scope'] ?? null)) {
                    $maps[(string) $class][(string) $name] = ['type' => $assignment['type'], 'scope' => $assignment['scope']];
                }
            }
        }

        return $maps;
    }

    /**
     * @param list<string> $files
     * @return array<string, array<string, array{type: string, scope: string}>>
     */
    private function run(array $files): array
    {
        $bootstrapFiles = $this->configuration->bootstrapFiles;
        if ($this->autoloadFile !== null && !\in_array($this->autoloadFile, $bootstrapFiles, true)) {
            array_unshift($bootstrapFiles, $this->autoloadFile);
        }

        $configFile = $this->configuration->tmpDir . '/detection.neon';
        file_put_contents($configFile, Neon::encode([
            'includes' => [\dirname(__DIR__, 2) . '/detection.neon'],
            'parameters' => [
                'level' => 0,
                'paths' => $files,
                'tmpDir' => $this->configuration->tmpDir . '/detection',
                'bootstrapFiles' => $bootstrapFiles,
                'reportUnmatchedIgnoredErrors' => false,
            ],
        ], true));

        $assignments = [];
        foreach ((new PhpStanRunner($this->phpstanBinary))->run($configFile)->errors as $error) {
            if ($error->identifier !== ViewHelperAssignmentRule::IDENTIFIER) {
                continue;
            }

            $payload = json_decode($error->message, true);
            if (!\is_array($payload) || !\is_string($payload['class'] ?? null) || !\is_string($payload['name'] ?? null) || !\is_string($payload['type'] ?? null)) {
                continue;
            }

            // Several assignments of the same variable: it holds one of the types; a narrower scope wins
            $scope = \is_string($payload['scope'] ?? null) ? $payload['scope'] : ViewHelperAssignments::SCOPE_TEMPLATE;
            $entry = $assignments[$payload['class']][$payload['name']] ?? ['types' => [], 'scope' => $scope];
            $entry['types'][$payload['type']] = true;
            if ($scope !== ViewHelperAssignments::SCOPE_TEMPLATE) {
                $entry['scope'] = $scope;
            }

            $assignments[$payload['class']][$payload['name']] = $entry;
        }

        return array_map(
            static fn(array $variables): array => array_map(
                static fn(array $entry): array => ['type' => implode('|', array_keys($entry['types'])), 'scope' => $entry['scope']],
                $variables,
            ),
            $assignments,
        );
    }

    /**
     * The files of the classes, their parents and traits, without Fluid's own ViewHelpers.
     *
     * @param list<string> $classes
     * @return list<string>
     */
    private function classFiles(array $classes): array
    {
        $files = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            for ($reflection = new \ReflectionClass($class); $reflection instanceof \ReflectionClass; $reflection = $reflection->getParentClass()) {
                foreach ([$reflection, ...self::traits($reflection)] as $declaring) {
                    $file = $declaring->getFileName();
                    if ($file !== false && !str_starts_with($declaring->getName(), 'TYPO3Fluid\\Fluid\\')) {
                        $files[$file] = true;
                    }
                }
            }
        }

        $files = array_keys($files);
        sort($files);
        return $files;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @return list<\ReflectionClass<object>>
     */
    private static function traits(\ReflectionClass $class): array
    {
        $traits = [];
        foreach ($class->getTraits() as $trait) {
            $traits[] = $trait;
            $traits = [...$traits, ...self::traits($trait)];
        }

        return $traits;
    }
}
