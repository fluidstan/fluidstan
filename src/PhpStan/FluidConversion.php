<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use PHPStan\Type\BooleanType;
use PHPStan\Type\IsSuperTypeOfResult;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use TYPO3Fluid\Fluid\Core\Parser\UnsafeHTML;

/**
 * Fluid's BooleanParser::convertNodeToBoolean(), which every operand of a condition passes through
 * before a comparison or arithmetic: a Countable becomes a bool, UnsafeHTML (Fluid 5) a string,
 * anything else stays what it is.
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
            $unsafeHtml = self::isUnsafeHtml($type);
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
     * Fluid 4 has no UnsafeHTML; PHPStan would answer "maybe" for the unknown interface and every
     * operand would turn into a possible string.
     */
    private static function isUnsafeHtml(Type $type): IsSuperTypeOfResult
    {
        if (!interface_exists(self::UNSAFE_HTML)) {
            return IsSuperTypeOfResult::createNo();
        }

        return (new ObjectType(self::UNSAFE_HTML))->isSuperTypeOf($type);
    }

    /**
     * Whether the conversion can change the outcome of a comparison with the operand: a possible
     * Countable is compared as a bool, a possible UnsafeHTML as a string, whatever the other side is
     * (two Countables compare their bools).
     */
    public static function affectsComparison(Type $operand): bool
    {
        return !(new ObjectType(\Countable::class))->isSuperTypeOf($operand)->no()
            || !self::isUnsafeHtml($operand)->no();
    }
}
