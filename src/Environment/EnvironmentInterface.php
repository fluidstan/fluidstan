<?php

declare(strict_types=1);

namespace FluidStan\Environment;

use FluidStan\Configuration\Configuration;

/**
 * Integration point for frameworks (see fluidstan/typo3): boots the framework, contributes
 * its Fluid namespaces and resolves framework-specific paths.
 *
 * Configured as `environment: My\Environment` in fluidstan.neon; the class must be
 * autoloadable after the configured bootstrapFiles have been required.
 */
interface EnvironmentInterface
{
    public function boot(Configuration $configuration): void;

    /**
     * Fluid namespaces, merged over the configured ones: identifier => PHP namespaces or
     * ViewHelperResolverDelegate classes (e.g. component collections).
     *
     * @return array<string, list<string>>
     */
    public function getNamespaces(): array;

    /**
     * Resolves a framework-specific path notation such as "EXT:my_ext/Resources/Private";
     * returns null when the path is not framework-specific.
     */
    public function resolvePath(string $path): ?string;

    /**
     * Files the PHPStan process must require in addition to the configured bootstrapFiles.
     *
     * @return list<string>
     */
    public function getPhpStanBootstrapFiles(): array;
}
