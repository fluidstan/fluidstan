<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use FluidStan\Runtime\F;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\StaticMethodTypeSpecifyingExtension;

/**
 * What a condition says about its operands in the branches of f:if.
 *
 * `F::bool($x)`, an operand used as a boolean: in the true branch the value is not null, false,
 * empty or zero. Fluid's truthiness (BooleanNode::convertToBoolean()) differs from PHP's only in
 * what it rejects, the string "false" and an empty Countable, so narrowing with PHP's rules is
 * sound in the true branch; the false branch is left alone.
 *
 * `F::compare($x, $operator, $y)`: Fluid's BooleanParser applies the PHP operator, so the comparison
 * is handed to PHPStan as that PHP expression and narrows the way PHPStan narrows it. Fluid first
 * turns a Countable operand into a bool and UnsafeHTML into a string (FluidConversion); where that
 * can change the outcome, nothing is narrowed, because PHPStan would reason about the raw object.
 */
final class ConditionTypeSpecifyingExtension implements StaticMethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    // Set by PHPStan through TypeSpecifierAwareExtension before any call
    private TypeSpecifier $typeSpecifier;

    public function getClass(): string
    {
        return F::class;
    }

    public function isStaticMethodSupported(MethodReflection $staticMethodReflection, StaticCall $node, TypeSpecifierContext $context): bool
    {
        return match ($staticMethodReflection->getName()) {
            'bool' => $context->truthy() && isset($node->getArgs()[0]),
            'compare' => isset($node->getArgs()[2]),
            default => false,
        };
    }

    public function specifyTypes(MethodReflection $staticMethodReflection, StaticCall $node, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
    {
        $args = $node->getArgs();
        if ($staticMethodReflection->getName() === 'bool') {
            return isset($args[0]) ? $this->typeSpecifier->specifyTypesInCondition($scope, $args[0]->value, $context) : new SpecifiedTypes();
        }

        if (!isset($args[0], $args[1], $args[2])) {
            return new SpecifiedTypes();
        }

        $left = $args[0]->value;
        $right = $args[2]->value;
        $operators = $scope->getType($args[1]->value)->getConstantStrings();
        $operator = \count($operators) === 1 ? $operators[0]->getValue() : null;
        $comparison = match ($operator) {
            '==' => new Equal($left, $right),
            '!=' => new NotEqual($left, $right),
            '===' => new Identical($left, $right),
            '!==' => new NotIdentical($left, $right),
            '<' => new Smaller($left, $right),
            '<=' => new SmallerOrEqual($left, $right),
            '>' => new Greater($left, $right),
            '>=' => new GreaterOrEqual($left, $right),
            default => null,
        };
        if ($comparison === null) {
            return new SpecifiedTypes();
        }

        $leftType = $scope->getType($left);
        $rightType = $scope->getType($right);
        if (FluidConversion::affectsComparison($leftType, $rightType) || FluidConversion::affectsComparison($rightType, $leftType)) {
            return new SpecifiedTypes();
        }

        return $this->typeSpecifier->specifyTypesInCondition($scope, $comparison, $context);
    }

    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }
}
