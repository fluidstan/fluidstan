<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

use FluidStan\Runtime\F;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;
use TYPO3Fluid\Fluid\Core\Parser\BooleanParser;

/**
 * Turns the PHP code Fluid's BooleanParser compiles for a condition into analysable code: the
 * `$context["nodeN"]` placeholders become the operand expressions, and the truthiness conversion
 * `BooleanParser::convertNodeToBoolean()` becomes `F::bool()` where the operand is used as a
 * boolean. Inside a comparison or arithmetic Fluid's conversion returns the value itself (only a
 * Countable becomes its count), so there the operand is used as is. Requires a
 * ParentConnectingVisitor to run first.
 */
final class ConditionCodeRewriter extends NodeVisitorAbstract
{
    private bool $complete = true;

    /**
     * @param array<string, Expr> $operands placeholder name => expression
     */
    public function __construct(private readonly array $operands) {}

    /**
     * Whether every placeholder was known
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Whether the expression already is a boolean: a logical operation, a comparison or a conversion
     */
    public static function isBoolean(Expr $expression): bool
    {
        if ($expression instanceof BooleanNot || $expression instanceof BooleanAnd || $expression instanceof BooleanOr || $expression instanceof LogicalAnd || $expression instanceof LogicalOr) {
            return true;
        }

        if ($expression instanceof Equal || $expression instanceof NotEqual || $expression instanceof Identical || $expression instanceof NotIdentical) {
            return true;
        }

        if ($expression instanceof Greater || $expression instanceof GreaterOrEqual || $expression instanceof Smaller || $expression instanceof SmallerOrEqual) {
            return true;
        }

        return $expression instanceof StaticCall && $expression->class instanceof Name && $expression->class->toString() === F::class
            && $expression->name instanceof Identifier && $expression->name->toString() === 'bool';
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof ArrayDimFetch && $node->var instanceof Variable && $node->var->name === 'context' && $node->dim instanceof String_) {
            $operand = $this->operands[$node->dim->value] ?? null;
            if ($operand === null) {
                $this->complete = false;
                return null;
            }

            return $operand;
        }

        if (!$node instanceof StaticCall || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return null;
        }

        if ($node->class->toString() !== BooleanParser::class || $node->name->toString() !== 'convertNodeToBoolean') {
            return null;
        }

        $value = $node->getArgs()[0]->value ?? null;
        if (!$value instanceof Expr) {
            $this->complete = false;
            return null;
        }

        $parent = $node->getAttribute('parent');
        $comparison = $parent instanceof BinaryOp && !$parent instanceof BooleanAnd && !$parent instanceof BooleanOr && !$parent instanceof LogicalAnd && !$parent instanceof LogicalOr;
        return $comparison ? $value : new StaticCall(new FullyQualified(F::class), 'bool', [new Arg($value)]);
    }
}
