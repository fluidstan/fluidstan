<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Fluid\AnalysisViewHelperResolver;
use FluidStan\Fluid\ComponentRegistry;
use FluidStan\Fluid\ComponentTemplate;
use FluidStan\Tests\Fixtures\Components\ComponentCollection;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\View\TemplatePaths;

final class ComponentRegistryTest extends TestCase
{
    public const COMPONENTS = __DIR__ . '/../Fixtures/Components';

    public function testFindsComponentsOfConfiguredCollections(): void
    {
        $registry = ComponentRegistry::fromNamespaces(
            ['f' => ['TYPO3Fluid\\Fluid\\ViewHelpers'], 'fs' => ['FluidStan\\Tests\\Does\\Not\\Exist', ComponentCollection::class]],
            new AnalysisViewHelperResolver(),
        );

        $component = $registry->find(self::COMPONENTS . '/Molecule/Card/Card.html');
        $this->assertInstanceOf(ComponentTemplate::class, $component);
        $this->assertSame('molecule.card', $component->name);
        $this->assertInstanceOf(ComponentCollection::class, $component->collection);
        $this->assertSame('atom.price', $registry->find(self::COMPONENTS . '/../Components/Atom/Price/Price.html')?->name);
        $this->assertNull($registry->find(__DIR__ . '/../Fixtures/Templates/Plain.html'));
        $this->assertNull($registry->find(self::COMPONENTS . '/ComponentCollection.php'));
    }

    public function testDerivesComponentNamesFromTemplateFilesWithoutComponentList(): void
    {
        // Fluid 4 collections provide no list of their components
        $collection = new class implements ComponentTemplateResolverInterface {
            public function getTemplatePaths(): TemplatePaths
            {
                $templatePaths = new TemplatePaths();
                $templatePaths->setTemplateRootPaths([ComponentRegistryTest::COMPONENTS]);
                return $templatePaths;
            }

            public function getAdditionalVariables(string $viewHelperName): array
            {
                return [];
            }

            public function resolveTemplateName(string $viewHelperName): string
            {
                $fragments = array_map(ucfirst(...), explode('.', $viewHelperName));
                $name = array_pop($fragments);
                $path = implode('/', $fragments);
                return ($path !== '' ? $path . '/' : '') . $name . '/' . $name;
            }
        };

        $registry = new ComponentRegistry($collection);
        $this->assertSame('molecule.card', $registry->find(self::COMPONENTS . '/Molecule/Card/Card.html')?->name);
        $this->assertSame('atom.typed', $registry->find(self::COMPONENTS . '/Atom/Typed/Typed.html')?->name);
    }
}
