<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;

/**
 * A template file seen as the component it is rendered as: its collection and its ViewHelper name.
 */
final readonly class ComponentTemplate
{
    public function __construct(
        public ComponentTemplateResolverInterface $collection,
        public string $name,
    ) {}
}
