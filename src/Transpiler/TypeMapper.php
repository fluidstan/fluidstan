<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

/**
 * Maps Fluid argument type strings (as used in f:argument and registerArgument()) to PHPDoc types.
 *
 * Two views exist on the same Fluid type: the type a component body may assume for its
 * parameter, and the type a ViewHelper or component accepts at a call site. The latter mirrors
 * StrictArgumentProcessor, which casts scalars first and validates afterwards.
 */
final class TypeMapper
{
    /**
     * @param bool $nullable whether the argument is optional with a null default
     */
    public function parameterType(string $fluidType, bool $nullable = false): string
    {
        $types = array_map($this->mapParameterMember(...), $this->members($fluidType));
        if ($nullable && !\in_array('null', $types, true) && !\in_array('mixed', $types, true)) {
            $types[] = 'null';
        }

        return $this->join($types);
    }

    public function acceptedType(string $fluidType): string
    {
        $types = array_map($this->mapAcceptedMember(...), $this->members($fluidType));
        return $this->join($types);
    }

    private function mapParameterMember(string $type): string
    {
        $lower = strtolower($type);
        if (str_ends_with($type, '[]')) {
            return 'iterable<' . $this->mapParameterMember(substr($type, 0, -2)) . '>';
        }

        return match ($lower) {
            'string' => 'string',
            'int', 'integer' => 'int',
            'float', 'double' => 'float',
            'bool', 'boolean' => 'bool',
            'array' => 'array',
            'mixed' => 'mixed',
            'object' => 'object',
            'iterable' => 'iterable',
            'countable' => '\\Countable|array',
            'callable' => 'callable',
            'null' => 'null',
            'scalar' => 'scalar',
            default => $this->className($type),
        };
    }

    private function mapAcceptedMember(string $type): string
    {
        $lower = strtolower($type);
        if (str_ends_with($type, '[]')) {
            return 'iterable<' . $this->mapElementMember(substr($type, 0, -2)) . '>|\\ArrayAccess';
        }

        return match ($lower) {
            'string' => 'scalar|\\Stringable',
            'int', 'integer', 'float', 'double', 'bool', 'boolean' => 'scalar',
            'array' => 'array|\\ArrayAccess|\\Traversable',
            'mixed' => 'mixed',
            'object' => 'object',
            'iterable' => 'iterable',
            'countable' => 'array|\\Countable',
            'callable' => 'callable',
            'null' => 'null',
            'scalar' => 'scalar',
            default => $this->className($type),
        };
    }

    /**
     * Element types of "Foo[]" are validated without casting, except that Stringable passes as string.
     */
    private function mapElementMember(string $type): string
    {
        $lower = strtolower($type);
        return match ($lower) {
            'string' => 'string|\\Stringable',
            default => $this->mapParameterMember($type),
        };
    }

    private function className(string $type): string
    {
        $type = ltrim($type, '\\');
        if ($type === '' || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $type)) {
            // Not a class name; let PHPStan report it as an unknown type
            return $type;
        }

        if (!str_contains($type, '\\') && ctype_lower($type[0])) {
            return $type;
        }

        return '\\' . $type;
    }

    /**
     * @return list<string>
     */
    private function members(string $fluidType): array
    {
        $members = array_values(array_filter(array_map(trim(...), explode('|', $fluidType)), static fn(string $m): bool => $m !== ''));
        return $members === [] ? ['mixed'] : $members;
    }

    /**
     * @param list<string> $types
     */
    private function join(array $types): string
    {
        if (\in_array('mixed', $types, true)) {
            return 'mixed';
        }

        return implode('|', array_values(array_unique($types)));
    }
}
