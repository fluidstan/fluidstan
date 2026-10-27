<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\Scope;

use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\View\TemplatePaths;

/**
 * Provides "v" to every component; "w" is never provided.
 */
final class ScopeCollection extends AbstractComponentCollection
{
    public function getTemplatePaths(): TemplatePaths
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths([__DIR__]);
        return $templatePaths;
    }

    public function getAdditionalVariables(string $viewHelperName): array
    {
        return ['v' => new Provided()];
    }
}
