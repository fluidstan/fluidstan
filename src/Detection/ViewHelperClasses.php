<?php

declare(strict_types=1);

namespace FluidStan\Detection;

use FluidStan\Fluid\ParsedTemplate;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ArrayNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\BooleanNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\EscapingNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;

/**
 * The ViewHelper classes a set of templates uses, tags and inline calls alike.
 */
final class ViewHelperClasses
{
    /**
     * @param list<ParsedTemplate> $templates
     * @return list<string> class names, sorted
     */
    public static function inTemplates(array $templates): array
    {
        $classes = [];
        foreach ($templates as $template) {
            if ($template->parsingState !== null) {
                self::collect($template->parsingState->getRootNode(), $classes);
            }
        }

        ksort($classes);
        return array_keys($classes);
    }

    /**
     * @param array<string, true> $classes
     */
    private static function collect(NodeInterface $node, array &$classes): void
    {
        if ($node instanceof EscapingNode) {
            $node = $node->getNode();
        }

        if ($node instanceof ViewHelperNode) {
            $classes[$node->getViewHelperClassName()] = true;
            foreach ($node->getArguments() as $argument) {
                if ($argument instanceof NodeInterface) {
                    self::collect($argument, $classes);
                }
            }
        } elseif ($node instanceof ArrayNode) {
            foreach (self::arrayItems($node) as $item) {
                if ($item instanceof NodeInterface) {
                    self::collect($item, $classes);
                }
            }
        } elseif ($node instanceof BooleanNode) {
            foreach ($node->getStack() as $item) {
                if ($item instanceof NodeInterface) {
                    self::collect($item, $classes);
                }
            }
        }

        foreach ($node->getChildNodes() as $child) {
            self::collect($child, $classes);
        }
    }

    /**
     * @return array<mixed>
     */
    private static function arrayItems(ArrayNode $node): array
    {
        $items = (new \ReflectionProperty(ArrayNode::class, 'internalArray'))->getValue($node);
        return \is_array($items) ? $items : [];
    }
}
