<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

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
}
