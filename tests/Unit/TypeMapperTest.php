<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Transpiler\TypeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function parameterTypes(): iterable
    {
        yield 'string' => ['string', false, 'string'];
        yield 'nullable string' => ['string', true, 'string|null'];
        yield 'integer alias' => ['integer', false, 'int'];
        yield 'boolean alias' => ['boolean', false, 'bool'];
        yield 'class' => ['App\\Model\\Item', false, '\\App\\Model\\Item'];
        yield 'leading backslash' => ['\\App\\Model\\Item', false, '\\App\\Model\\Item'];
        yield 'typed array' => ['App\\Model\\Item[]', false, 'iterable<\\App\\Model\\Item>'];
        yield 'string array' => ['string[]', false, 'iterable<string>'];
        yield 'union' => ['string|int', false, 'string|int'];
        yield 'mixed swallows union' => ['string|mixed', true, 'mixed'];
        yield 'countable' => ['countable', false, '\\Countable|array'];
        yield 'DateTime' => ['DateTime', false, '\\DateTime'];
        yield 'float alias' => ['double', false, 'float'];
        yield 'object' => ['object', false, 'object'];
        yield 'iterable' => ['iterable', false, 'iterable'];
        yield 'callable' => ['callable', false, 'callable'];
        yield 'scalar' => ['scalar', false, 'scalar'];
        yield 'explicit null member is not doubled' => ['string|null', true, 'string|null'];
        yield 'empty type is mixed' => ['', false, 'mixed'];
        yield 'invalid type stays for PHPStan to report' => ['no spaces here', false, 'no spaces here'];
        yield 'lowercase word stays' => ['strings', false, 'strings'];
        yield 'nested array' => ['int[][]', false, 'iterable<iterable<int>>'];
        yield 'duplicates collapse' => ['int|integer', false, 'int'];
    }

    #[DataProvider('parameterTypes')]
    public function testParameterType(string $fluidType, bool $nullable, string $expected): void
    {
        $this->assertSame($expected, (new TypeMapper())->parameterType($fluidType, $nullable));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedTypes(): iterable
    {
        yield 'string accepts scalars and Stringable' => ['string', 'scalar|\\Stringable'];
        yield 'int accepts scalars' => ['int', 'scalar'];
        yield 'array accepts ArrayAccess and Traversable' => ['array', 'array|\\ArrayAccess|\\Traversable'];
        yield 'typed array' => ['App\\Item[]', 'iterable<\\App\\Item>|\\ArrayAccess'];
        yield 'string array allows Stringable elements' => ['string[]', 'iterable<string|\\Stringable>|\\ArrayAccess'];
        yield 'class' => ['App\\Item', '\\App\\Item'];
        yield 'mixed' => ['mixed', 'mixed'];
        yield 'float accepts scalars' => ['float', 'scalar'];
        yield 'bool accepts scalars' => ['boolean', 'scalar'];
        yield 'countable' => ['countable', 'array|\\Countable'];
        yield 'object' => ['object', 'object'];
        yield 'iterable' => ['iterable', 'iterable'];
        yield 'callable' => ['callable', 'callable'];
        yield 'nullable union' => ['string|null', 'scalar|\\Stringable|null'];
        yield 'int elements are strict' => ['int[]', 'iterable<int>|\\ArrayAccess'];
        yield 'mixed member swallows the union' => ['string|mixed', 'mixed'];
    }

    #[DataProvider('acceptedTypes')]
    public function testAcceptedType(string $fluidType, string $expected): void
    {
        $this->assertSame($expected, (new TypeMapper())->acceptedType($fluidType));
    }
}
