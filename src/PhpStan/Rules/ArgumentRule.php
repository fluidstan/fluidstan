<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\Transpiler\TypeMapper;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * ViewHelper and component arguments must satisfy the argument definition, as validated at
 * runtime by Fluid's StrictArgumentProcessor. In strict mode a scalar that passes only because
 * Fluid casts it to the declared type is reported as well.
 */
final class ArgumentRule extends FacadeCallRule
{
    private readonly TypeMapper $typeMapper;

    public function __construct(
        private readonly TypeStringResolver $typeStringResolver,
        private readonly bool $strict,
    ) {
        $this->typeMapper = new TypeMapper();
    }

    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if ($method !== 'arg') {
            return [];
        }

        $names = $this->argumentType($call, 0, $scope)->getConstantStrings();
        $types = $this->argumentType($call, 2, $scope)->getConstantStrings();
        $viewHelpers = $this->argumentType($call, 4, $scope)->getConstantStrings();
        if (\count($names) !== 1 || \count($types) !== 1 || \count($viewHelpers) !== 1) {
            return [];
        }

        $valueType = $this->argumentType($call, 1, $scope);
        if ($valueType instanceof MixedType) {
            return [];
        }

        $declared = $types[0]->getValue();
        if ($declared === 'mixed') {
            return [];
        }

        $required = $this->argumentType($call, 3, $scope)->isTrue()->yes();
        try {
            $acceptedType = $this->resolve($this->typeMapper->acceptedType($declared), $required);
        } catch (\Throwable) {
            return [RuleErrorBuilder::message(\sprintf('Argument "%s" of %s has an invalid type declaration "%s".', $names[0]->getValue(), $viewHelpers[0]->getValue(), $declared))
                ->identifier('fluidstan.argument.invalidType')
                ->build()];
        }

        if (!$acceptedType->accepts($valueType, true)->yes()) {
            return [RuleErrorBuilder::message(\sprintf(
                'Argument "%s" of %s expects %s, %s given.',
                $names[0]->getValue(),
                $viewHelpers[0]->getValue(),
                $acceptedType->describe(VerbosityLevel::typeOnly()),
                $valueType->describe(VerbosityLevel::typeOnly()),
            ))->identifier('fluidstan.argument.type')->build()];
        }

        if (!$this->strict) {
            return [];
        }

        if ($this->resolve($this->typeMapper->acceptedType($declared, true), $required)->accepts($valueType, true)->yes() && !$this->truncatesNumericString($declared, $valueType)) {
            return [];
        }

        return [RuleErrorBuilder::message(\sprintf(
            'Argument "%s" of %s is declared as %s, %s given: Fluid casts the value.',
            $names[0]->getValue(),
            $viewHelpers[0]->getValue(),
            $declared,
            $valueType->describe(VerbosityLevel::typeOnly()),
        ))->identifier('fluidstan.argument.cast')->build()];
    }

    /**
     * A numeric string survives the cast to int only when its value does: "01" and "1e3" keep it,
     * "1.5" is truncated and "9223372036854775808" capped at PHP_INT_MAX. PHPStan has no type for
     * integer-like strings, so a constant string is checked by its value and an unknown numeric-string
     * is given the benefit of the doubt.
     */
    private function truncatesNumericString(string $declared, Type $valueType): bool
    {
        if (!\in_array(strtolower($declared), ['int', 'integer'], true)) {
            return false;
        }

        foreach ($valueType->getConstantStrings() as $constant) {
            $value = trim($constant->getValue());
            if (is_numeric($value) && !$this->castToIntKeepsValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function castToIntKeepsValue(string $value): bool
    {
        if (preg_match('/^([+-]?)0*(\d+)$/', $value, $match) === 1) {
            // An integer literal: within range when its digits do not exceed those of the limit
            $limit = ltrim((string) ($match[1] === '-' ? PHP_INT_MIN : PHP_INT_MAX), '-');
            return \strlen($match[2]) < \strlen($limit) || (\strlen($match[2]) === \strlen($limit) && $match[2] <= $limit);
        }

        // A decimal or exponent form: integral and small enough for the float to be exact
        $float = (float) $value;
        return $float === floor($float) && abs($float) < 2 ** 53;
    }

    private function resolve(string $type, bool $required): Type
    {
        $resolved = $this->typeStringResolver->resolve($type);
        return $required ? $resolved : TypeCombinator::addNull($resolved);
    }
}
