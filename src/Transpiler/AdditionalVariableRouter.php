<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

use FluidStan\Fluid\ComponentTemplate;
use FluidStan\Runtime\F;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * In a component, a variable that is not an argument starts out with the value the collection's
 * getAdditionalVariables() provides, or none. The PHPStan extension resolves that from the method.
 *
 * A variable the template assigns somewhere (f:variable) is initialised with that value at the
 * start, so PHPStan's own flow analysis tells the local from the provided value in every branch
 * and loop iteration, as Fluid's variable container would. A variable that is only read goes
 * through the facade at each read, which also reports what the collection does not provide.
 * Loop variables shadow a variable for the loop only: Fluid backs them up and restores them.
 * Section closures have their own preamble and scope and are left alone.
 */
final class AdditionalVariableRouter extends NodeVisitorAbstract
{
    /** Attribute on Variable nodes: the Fluid name before it was sanitized into a PHP name */
    public const FLUID_NAME = 'fluidName';

    /** Attribute on an Assign inside a loop body whose variable Fluid restores when the loop ends */
    public const LOOP_ALIAS = 'loopAlias';

    /** Attribute on a Block: the PHP names of variables that exist inside the block only (f:alias, f:cycle, scoped ViewHelpers) */
    public const SCOPE = 'scope';

    /** @var array<int, array{list<Stmt>, list<Stmt>}> backup and restore statements around a loop, by object id */
    private array $surroundings = [];

    private int $loops = 0;

    /**
     * @param array<string, true> $assigned PHP variable names that have a value before the body, such as the arguments
     */
    public function __construct(private readonly ComponentTemplate $component, private array $assigned) {}

    /**
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    public function route(array $statements): array
    {
        $preamble = [];
        foreach (array_diff_key($this->scanAssignments($statements), $this->assigned) as $phpName => $fluidName) {
            $statement = new Expression(new Assign(new Variable($phpName), $this->lookup($fluidName, [], true)));
            $statement->setAttribute('comments', [new Comment(GeneratedFile::LINE_MARKER . '1')]);
            $preamble[] = $statement;
            $this->assigned[$phpName] = true;
        }

        return [...$preamble, ...$this->traverse($statements)];
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
            $this->markAssigned($node->var);
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Foreach_) {
            return $this->enterLoop($node);
        }

        if ($node instanceof Block && \is_array($node->getAttribute(self::SCOPE))) {
            return $this->enterScope($node);
        }

        return $node instanceof Closure ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
    }

    /**
     * A block whose variables exist for its statements only: Fluid gives the children a scoped
     * variable provider and takes it away again afterwards.
     */
    private function enterScope(Block $block): int
    {
        $names = [];
        foreach ((array) $block->getAttribute(self::SCOPE) as $name) {
            if (\is_string($name)) {
                $names[$name] = true;
            }
        }

        $before = $this->assigned;
        [$pre, $post] = $this->backups(array_keys(array_intersect_key($names, $before)), $block);
        $block->stmts = $this->traverse(array_values($block->stmts));
        $this->assigned = array_diff_key($this->assigned, array_diff_key($names, $before));
        $this->surroundings[spl_object_id($block)] = [$pre, $post];
        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /**
     * Fluid backs up a variable that a loop or a scope shadows and restores it afterwards.
     *
     * @param list<string> $names
     * @return array{list<Stmt>, list<Stmt>}
     */
    private function backups(array $names, Node $node): array
    {
        $pre = [];
        $post = [];
        ++$this->loops;
        foreach ($names as $name) {
            $backup = new Variable(\sprintf('__backup%d_%s', $this->loops, $name));
            $pre[] = new Expression(new Assign($backup, new Variable($name)));
            $post[] = new Expression(new Assign(new Variable($name), $backup));
        }

        foreach ([...$pre, ...$post] as $statement) {
            $statement->setAttribute('comments', $node->getComments());
        }

        return [$pre, $post];
    }

    /**
     * @return list<Node>|null
     */
    public function leaveNode(Node $node): ?array
    {
        $surroundings = $this->surroundings[spl_object_id($node)] ?? null;
        if ($surroundings === null) {
            return null;
        }

        unset($this->surroundings[spl_object_id($node)]);
        [$before, $after] = $surroundings;
        return [...$before, $node, ...$after];
    }

    private function enterLoop(Foreach_ $loop): int
    {
        $loop->expr = $this->routeExpression($loop->expr);
        $before = $this->assigned;
        $aliases = $this->scanLoopAliases($loop->stmts);
        foreach ([$loop->valueVar, $loop->keyVar] as $target) {
            if ($target instanceof Variable && \is_string($target->name)) {
                $aliases[$target->name] = true;
            }
        }

        [$pre, $post] = $this->backups(array_keys(array_intersect_key($aliases, $before)), $loop);
        $this->markAssigned($loop->valueVar);
        $this->markAssigned($loop->keyVar);
        $loop->stmts = $this->traverse(array_values($loop->stmts));

        // Loop variables that did not exist before the loop are gone again
        $this->assigned = array_diff_key($this->assigned, array_diff_key($aliases, $before));
        $this->surroundings[spl_object_id($loop)] = [$pre, $post];
        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /**
     * Variables the statements assign persistently (f:variable), at any depth except inside sections.
     *
     * @param array<Stmt> $statements
     * @return array<string, string> PHP name => Fluid name
     */
    private function scanAssignments(array $statements): array
    {
        $assignments = [];
        $this->scan($statements, static function (Assign $assign, int $loopDepth) use (&$assignments): void {
            if ($assign->getAttribute(self::LOOP_ALIAS) === true || !$assign->var instanceof Variable || !\is_string($assign->var->name)) {
                return;
            }

            $fluidName = $assign->var->getAttribute(self::FLUID_NAME);
            $assignments[$assign->var->name] = \is_string($fluidName) ? $fluidName : $assign->var->name;
        });

        return $assignments;
    }

    /**
     * The loop's own aliases, such as its iteration variable; nested loops restore theirs themselves.
     *
     * @param array<Stmt> $statements
     * @return array<string, true>
     */
    private function scanLoopAliases(array $statements): array
    {
        $aliases = [];
        $this->scan($statements, static function (Assign $assign, int $loopDepth) use (&$aliases): void {
            if ($loopDepth === 0 && $assign->getAttribute(self::LOOP_ALIAS) === true && $assign->var instanceof Variable && \is_string($assign->var->name)) {
                $aliases[$assign->var->name] = true;
            }
        });

        return $aliases;
    }

    /**
     * @param array<Stmt> $statements
     * @param callable(Assign, int): void $onAssign called with the nesting depth in loops
     */
    private function scan(array $statements, callable $onAssign): void
    {
        $visitor = new class ($onAssign) extends NodeVisitorAbstract {
            private int $loopDepth = 0;

            /**
             * @param callable(Assign, int): void $onAssign
             */
            public function __construct(private $onAssign) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Closure) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Foreach_) {
                    ++$this->loopDepth;
                } elseif ($node instanceof Assign) {
                    ($this->onAssign)($node, $this->loopDepth);
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Foreach_) {
                    --$this->loopDepth;
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse(array_values($statements));
    }

    /**
     * @param array<string, mixed> $attributes
     * @param bool $maybeLocal the variable is assigned locally somewhere; the collection not providing it is no error then
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

    /**
     * @param list<Node> $nodes
     * @return list<Stmt>
     */
    private function traverse(array $nodes): array
    {
        $statements = [];
        foreach ((new NodeTraverser($this))->traverse($nodes) as $node) {
            if ($node instanceof Stmt) {
                $statements[] = $node;
            }
        }

        return $statements;
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
