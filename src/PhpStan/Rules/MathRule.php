<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\PhpStan\PropertyPathResolver;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\VerbosityLevel;

/**
 * Operands of `{a + b}` are cast to int or float; arrays, objects and non-numeric strings are bugs.
 */
final class MathRule extends FacadeCallRule
{
    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if ($method !== 'math') {
            return [];
        }

        $errors = [];
        foreach ($call->getArgs() as $arg) {
            foreach (PropertyPathResolver::members($scope->getType($arg->value)) as $member) {
                if ($member instanceof MixedType || $member->isNull()->yes() || $member->isInteger()->yes() || $member->isFloat()->yes() || $member->isBoolean()->yes()) {
                    continue;
                }

                if ($member->isString()->yes() && !$member->isNumericString()->no()) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf('Operand of type %s is not numeric.', $member->describe(VerbosityLevel::typeOnly())))
                    ->identifier('fluidstan.math.operand')
                    ->build();
            }
        }

        return $errors;
    }
}
