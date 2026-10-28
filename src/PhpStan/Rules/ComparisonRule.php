<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\PhpStan\FluidConversion;
use FluidStan\PhpStan\PropertyPathResolver;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Fluid compares with PHP's loose operators: `{count} == '5'` is true for the integer 5, and
 * `{flag} == 1` for every truthy value. Strict mode reports a comparison of scalars of different
 * kinds, where the outcome depends on PHP's conversion rather than on the values.
 */
final class ComparisonRule extends FacadeCallRule
{
    public function __construct(private readonly bool $strict = false) {}

    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if (!$this->strict || $method !== 'compare') {
            return [];
        }

        $operators = $this->argumentType($call, 1, $scope)->getConstantStrings();
        if (\count($operators) !== 1 || \in_array($operators[0]->getValue(), ['===', '!=='], true)) {
            return [];
        }

        // A Countable is compared as a bool and UnsafeHTML as a string; null compares loosely with anything
        $left = TypeCombinator::removeNull(FluidConversion::ofOperand($this->argumentType($call, 0, $scope)));
        $right = TypeCombinator::removeNull(FluidConversion::ofOperand($this->argumentType($call, 2, $scope)));
        $leftKinds = $this->scalarKinds($left);
        $rightKinds = $this->scalarKinds($right);
        // One kind on both sides compares without conversion; a union such as int|string may cross kinds
        if ($leftKinds === null || $rightKinds === null || \count(array_unique([...$leftKinds, ...$rightKinds])) === 1) {
            return [];
        }

        return [RuleErrorBuilder::message(\sprintf(
            "Comparing %s with %s using %s: Fluid compares loosely, so the outcome depends on PHP's type juggling.",
            $left->describe(VerbosityLevel::typeOnly()),
            $right->describe(VerbosityLevel::typeOnly()),
            $operators[0]->getValue(),
        ))->identifier('fluidstan.comparison.loose')->build()];
    }

    /**
     * The kinds of scalar the type may be, or null when it may be something else: a non-scalar
     * compares by identity or structure, which is not a matter of conversion.
     *
     * @return list<'string'|'number'|'bool'>|null
     */
    private function scalarKinds(Type $type): ?array
    {
        $kinds = [];
        foreach (PropertyPathResolver::members($type) as $member) {
            if ($member instanceof MixedType || $member->isNull()->yes()) {
                return null;
            }

            if ($member->isString()->yes()) {
                $kinds[] = 'string';
            } elseif ($member->isInteger()->yes() || $member->isFloat()->yes()) {
                $kinds[] = 'number';
            } elseif ($member->isBoolean()->yes()) {
                $kinds[] = 'bool';
            } else {
                return null;
            }
        }

        return $kinds === [] ? null : array_values(array_unique($kinds));
    }
}
