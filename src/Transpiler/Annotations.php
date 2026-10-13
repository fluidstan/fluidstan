<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

/**
 * Variable type declarations inside templates, e.g.
 *
 *   <f:comment>@var list<App\Model\Item> items</f:comment>
 *   <!-- @var App\Model\User $user -->
 *
 * They are the only way to type variables of non-component templates, and may override
 * the type of a component argument.
 */
final class Annotations
{
    /**
     * @return array<string, array{type: string, line: int}> variable name => PHPDoc type
     */
    public function parse(string $source): array
    {
        $result = [];
        // The type is everything between @var and the variable name at the end of the line, so
        // generics with spaces such as array<string, int> work.
        if (preg_match_all('/@var[ \t]+(?<type>[^\r\n]+?)[ \t]+\$?(?<name>[A-Za-z_][A-Za-z0-9_]*)[ \t]*(?:-->|<\/f:comment>)?[ \t]*$/m', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $result;
        }

        foreach ($matches['name'] as $index => [$name, $offset]) {
            $type = $matches['type'][$index][0] ?? '';
            $line = 1 + substr_count($source, "\n", 0, $offset);
            $result[$name] = ['type' => $type, 'line' => $line];
        }

        return $result;
    }
}
