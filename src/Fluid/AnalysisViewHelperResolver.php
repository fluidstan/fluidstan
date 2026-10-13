<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;

/**
 * ViewHelpers are only instantiated to read their argument definitions. Frameworks inject
 * services through constructors, which are not available during analysis, so instantiation
 * falls back to bypassing the constructor.
 */
final class AnalysisViewHelperResolver extends ViewHelperResolver
{
    public function createViewHelperInstanceFromClassName(string $viewHelperClassName): ViewHelperInterface
    {
        try {
            return parent::createViewHelperInstanceFromClassName($viewHelperClassName);
        } catch (\Throwable) {
            // Missing constructor arguments, or a constructor that resolves services from a container that is not available
            return (new \ReflectionClass($viewHelperClassName))->newInstanceWithoutConstructor();
        }
    }
}
