<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\ViewHelpers;

use FluidStan\Tests\Fixtures\Model\Item;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Assigns variables without any declaration; the detection pass finds them in the code.
 */
final class DetectedViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'string', 'The value', true);
        $this->registerArgument('as', 'string', 'The variable for the upper-cased value', false, 'upper');
    }

    public function render(): string
    {
        $provider = $this->renderingContext->getVariableProvider();
        $provider->add('detected', new Item('Detected', 1));
        $provider->addAll(['count' => 3, 'flags' => ['a', 'b']]);

        $value = $this->arguments['value'];
        if (!\is_string($value) || $value === '') {
            return '';
        }

        // A provider for a lookup of its own, never installed on the rendering context: assigns nothing
        $lookup = new StandardVariableProvider(['internal' => $value]);
        $lookup->getByPath('internal');

        // Reached only for a non-empty value: the variable may stay unassigned
        $target = (string) $this->arguments['as'];
        $provider->add($target, strtoupper($value));
        return '';
    }
}
