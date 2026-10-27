<?php

declare(strict_types=1);

namespace FluidStan\Runtime;

/**
 * Target of the generated PHP code. These methods are never executed: they give PHPStan
 * something to type. The FluidStan PHPStan extension refines their return types and adds
 * rules that mirror Fluid's runtime behaviour. Parameters are read by position in the
 * extension, so signatures are part of the contract with the transpiler.
 *
 * @internal
 */
final class F
{
    /**
     * `{subject.segment}` – property path access as done by StandardVariableProvider::getByPath()
     */
    public static function get(mixed $subject, string $segment): mixed
    {
        return null;
    }

    /**
     * `{subject.{key}}` – access with a dynamic key
     */
    public static function getDynamic(mixed $subject, mixed $key): mixed
    {
        return null;
    }

    /**
     * A variable whose origin is unknown (plain templates, settings, section arguments)
     */
    public static function variable(string $name): mixed
    {
        return null;
    }

    /**
     * A variable of a component that is not an argument: the collection may provide it through
     * getAdditionalVariables(), whose return type the PHPStan extension resolves.
     *
     * @param bool $maybeLocal the variable may have been assigned on another path; a name the collection
     *                         does not provide is null then, as Fluid returns null, instead of an error
     */
    public static function additionalVariable(string $collection, string $component, string $name, bool $maybeLocal = false): mixed
    {
        return null;
    }

    /**
     * `{_all}`
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [];
    }

    /**
     * Value rendered into the template output; Fluid casts it to string.
     */
    public static function output(mixed $value): void {}

    /**
     * The single child of a ViewHelper tag: Fluid passes it to the ViewHelper as is, without
     * casting it to string (AbstractNode::evaluateChildNodes()).
     */
    public static function child(mixed $value): void {}

    /**
     * Text with inline accessors, e.g. an argument value `a-button--{type}`
     */
    public static function concat(mixed ...$parts): string
    {
        return '';
    }

    /**
     * A single value as a condition, converted with Fluid's truthiness rules; the PHPStan extension
     * narrows the value in the true branch.
     */
    public static function bool(mixed $value): bool
    {
        return false;
    }

    /**
     * A comparison in a condition, evaluated with the PHP operator as Fluid's BooleanParser does; the
     * PHPStan extension narrows the operands in the branches.
     *
     * @param '=='|'!='|'==='|'!=='|'<'|'<='|'>'|'>=' $operator
     */
    public static function compare(mixed $left, string $operator, mixed $right): bool
    {
        return false;
    }

    /**
     * A condition whose compiled form could not be used: its operands are checked, its outcome is unknown.
     */
    public static function condition(mixed ...$operands): bool
    {
        return false;
    }

    /**
     * `each` of f:for
     *
     * @return iterable<mixed, mixed>
     */
    public static function each(mixed $each): iterable
    {
        return [];
    }

    /**
     * `iteration` of f:for
     *
     * @return array{index: int, cycle: int, total: int, isFirst: bool, isLast: bool, isEven: bool, isOdd: bool}
     */
    public static function iteration(): array
    {
        return ['index' => 0, 'cycle' => 1, 'total' => 0, 'isFirst' => true, 'isLast' => true, 'isEven' => false, 'isOdd' => true];
    }

    /**
     * One element of an iterable (f:cycle)
     */
    public static function valueOf(mixed $iterable): mixed
    {
        return null;
    }

    /**
     * `{a + b}`
     */
    public static function math(mixed ...$operands): int|float
    {
        $result = 0;
        foreach ($operands as $operand) {
            $result += \is_int($operand) || \is_float($operand) ? $operand : 0;
        }

        return $result;
    }

    /**
     * `{value as type}`
     */
    public static function cast(mixed $value, string $type): mixed
    {
        return null;
    }

    /**
     * A ViewHelper call. Children are passed for analysis only.
     *
     * @param class-string $class
     * @param array<string, mixed> $arguments
     */
    public static function viewHelper(string $class, array $arguments, string $name, mixed $children = null): mixed
    {
        return null;
    }

    /**
     * A component call (Fluid components rendered via ComponentAdapter).
     *
     * @param array<string, mixed> $arguments
     */
    public static function component(string $name, array $arguments, mixed $children = null): string
    {
        return '';
    }

    /**
     * One ViewHelper or component argument; $type is the accepted PHPDoc type derived from the
     * ArgumentDefinition, $required whether null is rejected.
     */
    public static function arg(string $name, mixed $value, string $type, bool $required, string $viewHelper): mixed
    {
        return $value;
    }

    /**
     * f:render
     *
     * @param array<string, mixed> $arguments
     */
    public static function render(?string $section, ?string $partial, array $arguments): ?string
    {
        return $section ?? $partial;
    }

    /**
     * f:slot
     */
    public static function slot(string $name): string
    {
        return '';
    }

    /**
     * Rendered children captured into a variable (f:variable with tag content)
     */
    public static function captured(): string
    {
        return '';
    }
}
