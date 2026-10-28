<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\PhpStan\Truthiness;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\BooleanNode;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;

/**
 * The condition narrowing assumes that whatever Fluid considers true, PHP considers true as well;
 * only then is it sound to narrow with PHP's rules in the true branch of f:if. Checked against
 * Fluid's real conversion, which is the source of truth.
 */
final class TruthinessTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'zero' => [0];
        yield 'one' => [1];
        yield 'zero float' => [0.0];
        yield 'empty string' => [''];
        yield 'zero string' => ['0'];
        yield 'false string' => ['false'];
        yield 'FALSE string' => ['FALSE'];
        yield 'text' => ['text'];
        yield 'numeric string' => ['2'];
        yield 'empty array' => [[]];
        yield 'array' => [[1]];
        yield 'empty countable' => [new \ArrayObject()];
        yield 'countable' => [new \ArrayObject([1])];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('values')]
    public function testWhatFluidAcceptsPhpAcceptsToo(mixed $value): void
    {
        $fluid = BooleanNode::convertToBoolean($value, new RenderingContext());
        $this->assertTrue(!$fluid || (bool) $value, 'Fluid accepts the value, so PHP must accept it too');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesOnlyPhpAccepts(): iterable
    {
        yield 'false string' => ['false'];
        yield 'empty countable' => [new \ArrayObject()];
    }

    /**
     * Which is why the false branch of f:if is not narrowed
     */
    #[DataProvider('valuesOnlyPhpAccepts')]
    public function testFluidRejectsValuesPhpAccepts(mixed $value): void
    {
        $this->assertFalse(BooleanNode::convertToBoolean($value, new RenderingContext()));
        $this->assertTrue((bool) $value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function scalarValues(): iterable
    {
        foreach (self::values() as $name => [$value]) {
            if (!\is_object($value) && !\is_array($value)) {
                yield $name => [$value];
            }
        }
    }

    /**
     * Strict mode decides a condition from the type where it can: a constant is decided as Fluid
     * decides the value. Objects and arrays need PHPStan's reflection and are covered by the strict
     * fixtures.
     */
    #[DataProvider('scalarValues')]
    public function testDecidesConstantsAsFluidDoes(mixed $value): void
    {
        $truthiness = Truthiness::of(ConstantTypeHelper::getTypeFromValue($value));
        $this->assertSame(BooleanNode::convertToBoolean($value, new RenderingContext()), $truthiness->yes());
        $this->assertFalse($truthiness->maybe());
    }

    /**
     * @return iterable<string, array{Type, bool|null}>
     */
    public static function types(): iterable
    {
        yield 'mixed' => [new MixedType(), null];
        yield 'bool' => [new BooleanType(), null];
        yield 'int' => [new IntegerType(), null];
        yield 'positive int' => [IntegerRangeType::fromInterval(1, null), true];
        yield 'string' => [new StringType(), null];
        yield 'non-empty string may be "false"' => [TypeCombinator::intersect(new StringType(), new AccessoryNonEmptyStringType()), null];
        yield 'null' => [new NullType(), false];
        yield 'nullable int' => [TypeCombinator::addNull(new IntegerType()), null];
        yield 'null or positive int' => [TypeCombinator::addNull(IntegerRangeType::fromInterval(1, null)), null];
        yield 'positive int or float' => [TypeCombinator::union(IntegerRangeType::fromInterval(1, null), new FloatType()), null];
        yield 'positive int or negative int' => [TypeCombinator::union(IntegerRangeType::fromInterval(1, null), IntegerRangeType::fromInterval(null, -1)), true];
    }

    #[DataProvider('types')]
    public function testDecidesTypes(Type $type, ?bool $expected): void
    {
        $truthiness = Truthiness::of($type);
        $this->assertSame($expected, $truthiness->maybe() ? null : $truthiness->yes());
    }
}
