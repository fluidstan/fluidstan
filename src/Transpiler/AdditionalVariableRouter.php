<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

use FluidStan\Fluid\ComponentTemplate;
use FluidStan\Runtime\F;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * In a component, a variable that is read before it is assigned and is not an argument can only
 * come from the collection's getAdditionalVariables(). Such reads go through the facade, so the
 * PHPStan extension resolves the type from that method and reports what the collection does not
 * provide. Statements are walked in evaluation order; section closures have their own preamble
 * and scope and are left alone.
 */
final class AdditionalVariableRouter extends NodeVisitorAbstract
{
    /** Attribute on Variable nodes: the Fluid name before it was sanitized into a PHP name */
    public const FLUID_NAME = 'fluidName';

    /**
     * @param array<string, true> $assigned PHP variable names that are assigned before the body, such as the arguments
     */
    public function __construct(
        private readonly ComponentTemplate $component,
        private array $assigned,
    ) {}

    /**
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    public function route(array $statements): array
    {
        $routed = [];
        foreach ((new NodeTraverser($this))->traverse($statements) as $node) {
            if ($node instanceof Stmt) {
                $routed[] = $node;
            }
        }

        return $routed;
    }

    public function enterNode(Node $node): int|Node|null
    {
        if ($node instanceof Variable) {
            return \is_string($node->name) && !isset($this->assigned[$node->name]) ? $this->lookup($node, $node->name) : null;
        }

        if ($node instanceof Assign) {
            // The right-hand side is evaluated before the variable exists
            $node->expr = $this->routeExpression($node->expr);
            $this->markAssigned($node->var);
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Foreach_) {
            $node->expr = $this->routeExpression($node->expr);
            $this->markAssigned($node->valueVar);
            $this->markAssigned($node->keyVar);
            $node->stmts = $this->route(array_values($node->stmts));
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return $node instanceof Closure ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
    }

    private function lookup(Variable $variable, string $phpName): StaticCall
    {
        $fluidName = $variable->getAttribute(self::FLUID_NAME);
        return new StaticCall(new FullyQualified(F::class), 'additionalVariable', [
            new Arg(new ClassConstFetch(new FullyQualified($this->component->collection::class), 'class')),
            new Arg(new String_($this->component->name)),
            new Arg(new String_(\is_string($fluidName) ? $fluidName : $phpName)),
        ], $variable->getAttributes());
    }

    private function routeExpression(Expr $expression): Expr
    {
        $node = (new NodeTraverser($this))->traverse([$expression])[0] ?? $expression;
        return $node instanceof Expr ? $node : $expression;
    }

    private function markAssigned(?Node $target): void
    {
        if ($target instanceof Variable && \is_string($target->name)) {
            $this->assigned[$target->name] = true;
        }
    }
}
