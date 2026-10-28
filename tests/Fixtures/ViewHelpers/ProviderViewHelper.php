<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\ViewHelpers;

use FluidStan\Tests\Fixtures\Model\Item;
use TYPO3Fluid\Fluid\Core\Variables\ScopedVariableProvider;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Gives its children variables through a scoped variable provider, as f:alias does.
 */
class ProviderViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('as', 'string', 'The variable for the item', false, 'provided');
    }

    public function render(): mixed
    {
        $as = (string) $this->arguments['as'];
        $global = $this->renderingContext->getVariableProvider();
        $this->renderingContext->setVariableProvider(new ScopedVariableProvider($global, new StandardVariableProvider([
            'label' => 'provided',
            $as => new Item('Provided', 2),
        ])));
        $content = $this->renderChildren();
        $this->renderingContext->setVariableProvider($global);
        return $content;
    }
}
