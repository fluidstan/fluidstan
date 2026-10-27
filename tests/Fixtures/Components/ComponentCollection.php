<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\Components;

use FluidStan\Tests\Fixtures\Model\Item;
use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\View\TemplatePaths;

final class ComponentCollection extends AbstractComponentCollection
{
    public function getTemplatePaths(): TemplatePaths
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths([__DIR__]);
        return $templatePaths;
    }

    // No @return: the variables are inferred from the return statement, as PHPStan would not do for callers
    public function getAdditionalVariables(string $viewHelperName): array
    {
        $class = str_replace('.', '-', $viewHelperName);
        return [
            'component' => [
                'class' => $class,
                'prefix' => $class . '_',
                'langPrefix' => 'LLL:EXT:site/Resources/Private/Components/' . $this->resolveTemplateName($viewHelperName) . '.labels.xlf:',
                'flags' => ['a', 'b'],
            ],
            'version' => 1,
            'author' => new Item('Author', 0),
            'nothing' => null,
            'this' => 'a Fluid name that is not a PHP name',
        ];
    }
}
