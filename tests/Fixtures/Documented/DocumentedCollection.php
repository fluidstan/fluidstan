<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\Documented;

use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\View\TemplatePaths;

final class DocumentedCollection extends AbstractComponentCollection
{
    public function getTemplatePaths(): TemplatePaths
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths([__DIR__]);
        return $templatePaths;
    }

    /**
     * The declared shape is the contract and wins over what the body returns.
     *
     * @return array{meta: array{id: int}, label: string}
     */
    public function getAdditionalVariables(string $viewHelperName): array
    {
        return $this->variables($viewHelperName);
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(string $viewHelperName): array
    {
        return ['meta' => ['id' => 1, 'name' => $viewHelperName], 'label' => 'Note', 'extra' => true];
    }
}
