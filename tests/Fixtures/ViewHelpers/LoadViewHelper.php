<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\ViewHelpers;

use FluidStan\Tests\Fixtures\Model\Item;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Loads an item into the template variable "item"; declared in fluidstan.neon, as for a third-party ViewHelper.
 */
final class LoadViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('uid', 'int', 'The item', true);
    }

    public function render(): string
    {
        $this->renderingContext->getVariableProvider()->add('item', new Item('Item ' . $this->arguments['uid'], 0));
        return '';
    }
}
