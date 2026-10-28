<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Appends a value to a template variable, "class" unless "target" says otherwise.
 *
 * @fluidstan-assigns {target} string
 */
class AssignViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'string', 'The value', true);
        $this->registerArgument('target', 'string', 'The variable', false, 'class');
    }

    public function render(): string
    {
        $provider = $this->renderingContext->getVariableProvider();
        $name = (string) $this->arguments['target'];
        $provider->add($name, trim($provider->get($name) . ' ' . $this->arguments['value']));
        return '';
    }
}
