<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures;

use FluidStan\Configuration\Configuration;
use FluidStan\Environment\EnvironmentInterface;
use FluidStan\Tests\Fixtures\Components\ComponentCollection;

/**
 * Registers a collection under a namespace string that is not a class name, the way TYPO3 14
 * registers collections configured in Configuration/Fluid/ComponentCollections.php.
 */
final class DelegatingEnvironment implements EnvironmentInterface
{
    public const NAMESPACE = 'Vendor\\Site\\Components';

    public function boot(Configuration $configuration): void {}

    public function getNamespaces(): array
    {
        return ['x' => [self::NAMESPACE]];
    }

    public function getResolverDelegates(): array
    {
        return [self::NAMESPACE => new ComponentCollection()];
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
