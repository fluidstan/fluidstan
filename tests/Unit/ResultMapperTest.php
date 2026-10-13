<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Runner\ResultMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResultMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'undefined variable' => ['Undefined variable: $item', 'Variable "item" is not defined.'];
        yield 'maybe undefined' => ['Variable $item might not be defined.', 'Variable "item" might not be defined.'];
        yield 'unknown class in param' => [
            'PHPDoc tag @param for parameter $item contains unknown class App\Nope.',
            'Argument "item" has unknown class App\Nope.',
        ];
        yield 'untouched' => ['Something else', 'Something else'];
    }

    #[DataProvider('messages')]
    public function testRewrite(string $message, string $expected): void
    {
        $this->assertSame($expected, ResultMapper::rewrite($message));
    }
}
