<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

use FluidStan\Fluid\ComponentTemplate;
use FluidStan\Runtime\F;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * In a component, a variable that is read before it is assigned and is not an argument can only
 * come from the collection's getAdditionalVariables(). Such reads go through the facade, so the
 * PHPStan extension resolves the type from that method and reports what the collection does not
 * provide.
 *
 * Statements are walked in evaluation order with Fluid's scoping: a variable assigned on some
 * paths of a condition keeps the local value there and the collection's elsewhere, loop variables
 * are restored when the loop ends, and section closures have their own preamble and scope.
 */
final class AdditionalVariableRouter extends NodeVisitorAbstract
{
    /** Attribute on Variable nodes: the Fluid name before it was sanitized into a PHP name */
    public const FLUID_NAME = 'fluidName';

    /** Attribute on an Assign inside a loop body whose variable Fluid restores when the loop ends */
    public const LOOP_ALIAS = 'loopAlias';

    /** @var array<string, string> PHP name => Fluid name of assigned variables */
    private array $fluidNames = [];

    /** @var list<array<string, true>> loop variables of the open loops, innermost last */
    private array $loopAliases = [];

    /** @var array<int, list<Stmt>> statements to append after a node, by object id */
    private array $joins = [];

    /**
     * @param array<string, true> $assigned PHP variable names that are assigned before the body, such as the arguments
     */
    public function __construct(private readonly ComponentTemplate $component, private array $assigned) {}

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
            if (!\is_string($node->name) || isset($this->assigned[$node->name])) {
                return null;
            }

            $fluidName = $node->getAttribute(self::FLUID_NAME);
            return $this->lookup(\is_string($fluidName) ? $fluidName : $node->name, $node->getAttributes());
        }

        if ($node instanceof Assign) {
            // The right-hand side is evaluated before the variable exists
            $node->expr = $this->routeExpression($node->expr);
            $this->markAssigned($node->var, $node->getAttribute(self::LOOP_ALIAS) === true);
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof If_) {
            return $this->enterIf($node);
        }

        if ($node instanceof Foreach_) {
            return $this->enterLoop($node);
        }

        return $node instanceof Closure ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
    }

    /**
     * @return list<Node>|null
     */
    public function leaveNode(Node $node): ?array
    {
        $joins = $this->joins[spl_object_id($node)] ?? null;
        if ($joins === null) {
            return null;
        }

        unset($this->joins[spl_object_id($node)]);
        return [$node, ...$joins];
    }

    private function enterIf(If_ $if): int
    {
        $if->cond = $this->routeExpression($if->cond);
        $before = $this->assigned;
        $paths = [];
        $if->stmts = $this->routePath($if->stmts, $before, $paths);
        foreach ($if->elseifs as $elseIf) {
            $this->assigned = $before;
            $elseIf->cond = $this->routeExpression($elseIf->cond);
            $elseIf->stmts = $this->routePath($elseIf->stmts, $before, $paths);
        }

        if ($if->else instanceof Else_) {
            $if->else->stmts = $this->routePath($if->else->stmts, $before, $paths);
        } else {
            // No branch may apply
            $paths[] = $before;
        }

        $this->join($if, $paths);
        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    private function enterLoop(Foreach_ $loop): int
    {
        $loop->expr = $this->routeExpression($loop->expr);
        $before = $this->assigned;
        $this->loopAliases[] = [];
        $this->markAssigned($loop->valueVar, true);
        $this->markAssigned($loop->keyVar, true);
        $loop->stmts = $this->route(array_values($loop->stmts));
        $aliases = array_pop($this->loopAliases) ?? [];

        // Fluid restores the loop variables when the loop ends, and the body may not have run at all
        $afterBody = array_merge($before, array_diff_key($this->assigned, $aliases));
        $this->join($loop, [$before, $afterBody]);
        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /**
     * @param array<Stmt> $statements
     * @param array<string, true> $before
     * @param list<array<string, true>> $paths
     * @return list<Stmt>
     */
    private function routePath(array $statements, array $before, array &$paths): array
    {
        $this->assigned = $before;
        $statements = $this->route(array_values($statements));
        $paths[] = $this->assigned;
        return $statements;
    }

    /**
     * A variable assigned on every path is assigned afterwards. One assigned on some paths keeps the
     * local value there and the collection's value elsewhere: `$name = $name ?? lookup` after the
     * node gives it that union and makes it assigned from here on.
     *
     * @param list<array<string, true>> $paths the variables assigned at the end of each path
     */
    private function join(Node $node, array $paths): void
    {
        $everywhere = array_intersect_key(...$paths);
        $somewhere = array_merge(...$paths);
        $this->assigned = $everywhere;

        $joins = [];
        foreach (array_keys(array_diff_key($somewhere, $everywhere)) as $name) {
            $lookup = $this->lookup($this->fluidNames[$name] ?? $name, [], true);
            $join = new Expression(new Assign(new Variable($name), new Coalesce(new Variable($name), $lookup)));
            // Reported at the line of the condition or loop
            $join->setAttribute('comments', $node->getComments());
            $joins[] = $join;
            $this->assigned[$name] = true;
        }

        if ($joins !== []) {
            $this->joins[spl_object_id($node)] = $joins;
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @param bool $maybeLocal the variable may have been assigned on another path; the collection not providing it is no error then
     */
    private function lookup(string $fluidName, array $attributes, bool $maybeLocal = false): StaticCall
    {
        $arguments = [
            new Arg(new ClassConstFetch(new FullyQualified($this->component->collection::class), 'class')),
            new Arg(new String_($this->component->name)),
            new Arg(new String_($fluidName)),
        ];
        if ($maybeLocal) {
            $arguments[] = new Arg(new ConstFetch(new Name('true')));
        }

        return new StaticCall(new FullyQualified(F::class), 'additionalVariable', $arguments, $attributes);
    }

    private function routeExpression(Expr $expression): Expr
    {
        $node = (new NodeTraverser($this))->traverse([$expression])[0] ?? $expression;
        return $node instanceof Expr ? $node : $expression;
    }

    private function markAssigned(?Node $target, bool $loopAlias): void
    {
        if (!$target instanceof Variable || !\is_string($target->name)) {
            return;
        }

        $this->assigned[$target->name] = true;
        $fluidName = $target->getAttribute(self::FLUID_NAME);
        $this->fluidNames[$target->name] = \is_string($fluidName) ? $fluidName : $target->name;
        if ($loopAlias && $this->loopAliases !== []) {
            $this->loopAliases[array_key_last($this->loopAliases)][$target->name] = true;
        }
    }
}
