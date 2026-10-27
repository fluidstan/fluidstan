<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use FluidStan\Runtime\F;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\StaticMethodTypeSpecifyingExtension;

/**
 * `F::bool($x)`, an operand used as a boolean: in the true branch the value is not null, false,
 * empty or zero. Fluid's truthiness (BooleanNode::convertToBoolean()) differs from PHP's only in
 * what it rejects, the string "false" and an empty Countable, so narrowing with PHP's rules is
 * sound in the true branch; the false branch is left alone. Comparisons are PHP comparisons in the
 * generated code and narrow the way PHPStan narrows them.
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
        return $staticMethodReflection->getName() === 'bool' && $context->truthy() && isset($node->getArgs()[0]);
    }

    public function specifyTypes(MethodReflection $staticMethodReflection, StaticCall $node, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
    {
        $argument = $node->getArgs()[0] ?? null;
        if ($argument === null) {
            return new SpecifiedTypes();
        }

        return $this->typeSpecifier->specifyTypesInCondition($scope, $argument->value, $context);
    }

    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }
}
