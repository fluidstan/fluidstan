<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

/**
 * ViewHelpers are only instantiated to read their argument definitions. Frameworks inject
 * services through constructors, which are not available during analysis, so instantiation
 * falls back to bypassing the constructor.
 */
final class AnalysisViewHelperResolver extends ViewHelperResolver
{
    /** @var array<string, ViewHelperResolverDelegateInterface> */
    private array $delegates = [];

    /**
     * Delegates built by the framework, keyed by the namespace string that refers to them; they take
     * precedence over instantiating a class of that name (TYPO3's ViewHelperResolver gets them the same way).
     *
     * @param array<string, ViewHelperResolverDelegateInterface> $delegates
     */
    public function setResolverDelegates(array $delegates): void
    {
        $this->delegates = $delegates;
    }

    public function createResolverDelegateInstanceFromClassName(string $delegateClassName): ViewHelperResolverDelegateInterface
    {
        return $this->delegates[$delegateClassName] ?? parent::createResolverDelegateInstanceFromClassName($delegateClassName);
    }

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
