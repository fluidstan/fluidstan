<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use PHPStan\TrinaryLogic;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\BooleanNode;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;

/**
 * What Fluid makes of a value used as a condition (BooleanNode::convertToBoolean()), as far as the
 * type tells: a constant is converted by Fluid itself; otherwise null is false, an object that is
 * not a Countable is true, a non-empty array is true, a number is true when it cannot be zero, and
 * a string is left open because "false" and "0" are false whatever else is known about it.
 */
final class Truthiness
{
    public static function of(Type $type): TrinaryLogic
    {
        // A Countable is a bool, UnsafeHTML a string, before Fluid looks at the value
        return TrinaryLogic::extremeIdentity(...array_map(self::ofMember(...), PropertyPathResolver::members(FluidConversion::ofOperand($type))));
    }

    private static function ofMember(Type $type): TrinaryLogic
    {
        if ($type instanceof MixedType) {
            return TrinaryLogic::createMaybe();
        }

        if ($type->isConstantScalarValue()->yes()) {
            $values = $type->getConstantScalarValues();
            return TrinaryLogic::createFromBoolean(BooleanNode::convertToBoolean($values[0] ?? null, new RenderingContext()));
        }

        if ($type->isNull()->yes()) {
            return TrinaryLogic::createNo();
        }

        if ($type->isObject()->yes()) {
            return TrinaryLogic::createYes();
        }

        if ($type->isString()->yes() || $type->isBoolean()->yes()) {
            return TrinaryLogic::createMaybe();
        }

        if ($type->isInteger()->yes() || $type->isFloat()->yes() || $type->isArray()->yes()) {
            // PHP agrees with Fluid on numbers and arrays: zero and empty are false
            $php = $type->toBoolean();
            return $php->isTrue()->yes() ? TrinaryLogic::createYes() : ($php->isFalse()->yes() ? TrinaryLogic::createNo() : TrinaryLogic::createMaybe());
        }

        return TrinaryLogic::createMaybe();
    }
}
