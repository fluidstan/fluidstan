<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Gives its children a variable and takes it away again: add(), renderChildren(), remove().
 */
class ScopedViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'mixed', 'The value', true);
        $this->registerArgument('as', 'string', 'The variable the children see', false, 'scoped');
    }

    public function render(): mixed
    {
        $provider = $this->renderingContext->getVariableProvider();
        $name = (string) $this->arguments['as'];
        $provider->add($name, $this->arguments['value']);
        $content = $this->renderChildren();
        $provider->remove($name);
        return $content;
    }
}
