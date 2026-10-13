<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\EscapingNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;

/**
 * Source positions of syntax tree nodes. Fluid's nodes don't carry positions themselves;
 * they are recorded while parsing (see LocationTrackingTemplateParser).
 */
final class NodeLocations
{
    /** @var \SplObjectStorage<NodeInterface, SourceLocation> */
    private \SplObjectStorage $locations;

    public function __construct()
    {
        $this->locations = new \SplObjectStorage();
    }

    public function set(NodeInterface $node, SourceLocation $location): void
    {
        $this->locations[$node] = $location;
    }

    public function has(NodeInterface $node): bool
    {
        return $this->locations->offsetExists($node);
    }

    public function get(NodeInterface $node): ?SourceLocation
    {
        if ($node instanceof EscapingNode && !$this->locations->offsetExists($node)) {
            $node = $node->getNode();
        }

        return $this->locations->offsetExists($node) ? $this->locations[$node] : null;
    }

    public function line(NodeInterface $node): ?int
    {
        return $this->get($node)?->line;
    }
}
