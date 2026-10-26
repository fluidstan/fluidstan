<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

/**
 * Maps component template files back to the collection and ViewHelper name they are rendered as.
 * Fluid only resolves the other direction (name to file); the reverse is needed to ask a collection
 * what it contributes to a component, such as getAdditionalVariables().
 */
final class ComponentRegistry
{
    /** @var list<ComponentTemplateResolverInterface> */
    private readonly array $collections;

    /** @var array<string, ComponentTemplate>|null template file (real path) => component */
    private ?array $components = null;

    public function __construct(ComponentTemplateResolverInterface ...$collections)
    {
        $this->collections = array_values($collections);
    }

    /**
     * @param array<string, list<string>|null> $namespaces identifier => PHP namespaces or collection classes, null for ignored namespaces
     * @param array<string, ViewHelperResolverDelegateInterface> $delegates delegates the framework built, keyed by namespace string
     */
    public static function fromNamespaces(array $namespaces, array $delegates, ViewHelperResolver $resolver): self
    {
        $collections = [];
        foreach ($delegates as $delegate) {
            if ($delegate instanceof ComponentTemplateResolverInterface) {
                $collections[] = $delegate;
            }
        }

        foreach (array_merge(...array_values(array_filter($namespaces, static fn(?array $entries): bool => $entries !== null))) as $class) {
            if (isset($delegates[$class])) {
                continue;
            }

            if (!is_a($class, ComponentTemplateResolverInterface::class, true)) {
                continue;
            }

            // Instantiated the way the parser instantiates the collection for its ViewHelper calls
            // (getResolverDelegate() exists since Fluid 4.6 only)
            $delegate = $resolver->createResolverDelegateInstanceFromClassName($class);
            if ($delegate instanceof ComponentTemplateResolverInterface) {
                $collections[] = $delegate;
            }
        }

        return new self(...$collections);
    }

    public function find(string $file): ?ComponentTemplate
    {
        $this->components ??= $this->discover();
        return $this->components[$this->key($file)] ?? null;
    }

    /**
     * @return array<string, ComponentTemplate>
     */
    private function discover(): array
    {
        $components = [];
        foreach ($this->collections as $collection) {
            $templatePaths = $collection->getTemplatePaths();
            foreach ($this->componentNames($collection) as $name) {
                $file = $templatePaths->resolveTemplateFileForControllerAndActionAndFormat('Default', $collection->resolveTemplateName($name));
                if ($file !== null) {
                    $components[$this->key($file)] ??= new ComponentTemplate($collection, $name);
                }
            }
        }

        return $components;
    }

    /**
     * @return list<string>
     */
    private function componentNames(ComponentTemplateResolverInterface $collection): array
    {
        if ($collection instanceof ComponentListProviderInterface) {
            return array_values($collection->getAvailableComponents());
        }

        // Fluid 4 has no component list. Derive the names from the template files the way
        // AbstractComponentCollection::getAvailableComponents() does since Fluid 5: the file
        // Atom/Button/Button.html is the component atom.button.
        $templatePaths = $collection->getTemplatePaths();
        $names = [];
        foreach ($templatePaths->resolveAvailableTemplateFiles(null) as $file) {
            foreach ($templatePaths->getTemplateRootPaths() as $rootPath) {
                if (str_starts_with($file, $rootPath)) {
                    $file = substr($file, \strlen($rootPath));
                    break;
                }
            }

            $fragments = explode('/', trim($file, '/'));
            $basename = array_pop($fragments);
            $name = str_contains($basename, '.') ? (string) strstr($basename, '.', true) : $basename;
            if ($fragments === [] || $name !== end($fragments)) {
                continue;
            }

            $names[] = implode('.', array_map(lcfirst(...), $fragments));
        }

        return $names;
    }

    private function key(string $file): string
    {
        $real = realpath($file);
        return $real === false ? $file : $real;
    }
}
