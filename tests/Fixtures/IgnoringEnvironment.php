<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures;

use FluidStan\Configuration\Configuration;
use FluidStan\Environment\EnvironmentInterface;

/**
 * Declares the "x" namespace as ignored, the way a framework may do for namespaces Fluid must leave alone.
 */
final class IgnoringEnvironment implements EnvironmentInterface
{
    public function boot(Configuration $configuration): void {}

    public function getNamespaces(): array
    {
        return ['x' => null];
    }

    public function getResolverDelegates(): array
    {
        return [];
    }

    public function resolvePath(string $path): ?string
    {
        return null;
    }

    public function getPhpStanBootstrapFiles(): array
    {
        return [];
    }
}
