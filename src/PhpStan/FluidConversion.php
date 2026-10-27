<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use PHPStan\Type\BooleanType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use TYPO3Fluid\Fluid\Core\Parser\UnsafeHTML;

/**
 * Fluid's BooleanParser::convertNodeToBoolean(), which every operand of a condition passes through
 * before a comparison or arithmetic: a Countable becomes a bool, UnsafeHTML a string, anything else
 * stays what it is.
 */
final class FluidConversion
{
    private const UNSAFE_HTML = UnsafeHTML::class;

    public static function ofOperand(Type $type): Type
    {
        return TypeTraverser::map($type, static function (Type $type, callable $traverse): Type {
            if ($type instanceof UnionType) {
                return $traverse($type);
            }

            $countable = (new ObjectType(\Countable::class))->isSuperTypeOf($type);
            $unsafeHtml = (new ObjectType(self::UNSAFE_HTML))->isSuperTypeOf($type);
            if ($countable->yes()) {
                return new BooleanType();
            }

            if ($unsafeHtml->yes()) {
                return new StringType();
            }

            // A non-final class may have a subclass that implements the interface
            $converted = $type;
            if ($countable->maybe()) {
                $converted = TypeCombinator::union($converted, new BooleanType());
            }

            if ($unsafeHtml->maybe()) {
                return TypeCombinator::union($converted, new StringType());
            }

            return $converted;
        });
    }

    /**
     * Whether the conversion can change the outcome of a comparison of the operand with the other
     * side: a possible Countable against anything a bool compares to, a possible UnsafeHTML against
     * anything a string compares to. Only another object is safe.
     */
    public static function affectsComparison(Type $operand, Type $other): bool
    {
        $maybeCountable = !(new ObjectType(\Countable::class))->isSuperTypeOf($operand)->no();
        $maybeUnsafeHtml = !(new ObjectType(self::UNSAFE_HTML))->isSuperTypeOf($operand)->no();
        return ($maybeCountable || $maybeUnsafeHtml) && !$other->isObject()->yes();
    }
}
