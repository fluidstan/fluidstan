<?php

declare(strict_types=1);

namespace FluidStan\Runner;

use FluidStan\Configuration\Configuration;
use Nette\Neon\Neon;

/**
 * Writes the PHPStan configuration used to analyse the generated code.
 */
final class GeneratedConfigWriter
{
    /**
     * @param list<string> $extraBootstrapFiles
     */
    public function write(Configuration $configuration, string $generatedDir, string $configFile, ?string $autoloadFile, array $extraBootstrapFiles = []): void
    {
        $bootstrapFiles = array_values(array_unique(array_merge($configuration->bootstrapFiles, $extraBootstrapFiles)));
        if ($autoloadFile !== null && !\in_array($autoloadFile, $bootstrapFiles, true)) {
            array_unshift($bootstrapFiles, $autoloadFile);
        }

        $parameters = array_merge([
            'level' => $configuration->level,
            'paths' => [$generatedDir],
            'tmpDir' => $configuration->tmpDir . '/phpstan',
            'bootstrapFiles' => $bootstrapFiles,
            'reportUnmatchedIgnoredErrors' => false,
            'treatPhpDocTypesAsCertain' => true,
            // Fluid's "array" argument type has no element type; that is not a template bug
            'ignoreErrors' => [
                ['identifier' => 'missingType.iterableValue'],
                ['identifier' => 'missingType.generics'],
            ],
        ], $configuration->phpstanParameters);
        $parameters['paths'] = [$generatedDir];

        $config = [
            'includes' => array_merge([self::extensionFile()], $configuration->phpstanIncludes),
            'parameters' => $parameters,
        ];
        if (!is_dir(\dirname($configFile)) && !mkdir(\dirname($configFile), 0o777, true) && !is_dir(\dirname($configFile))) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', \dirname($configFile)));
        }

        file_put_contents($configFile, Neon::encode($config, true));
    }

    public static function extensionFile(): string
    {
        return \dirname(__DIR__, 2) . '/extension.neon';
    }
}
