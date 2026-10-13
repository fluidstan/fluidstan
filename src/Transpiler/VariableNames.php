<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

final class VariableNames
{
    public static function php(string $fluidName): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $fluidName) ?? $fluidName;
        if ($name === '' || ctype_digit($name[0]) || $name === 'this') {
            return '_' . $name;
        }

        return $name;
    }

    public static function fluid(string $phpName): string
    {
        return $phpName;
    }
}
