<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\PhpStan\AdditionalVariablesResolver;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * A component variable that is neither an argument nor provided by the collection's
 * getAdditionalVariables() is undefined.
 */
final class AdditionalVariableRule extends FacadeCallRule
{
    public function __construct(private readonly AdditionalVariablesResolver $resolver) {}

    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if ($method !== 'additionalVariable') {
            return [];
        }

        $collections = $this->argumentType($call, 0, $scope)->getConstantStrings();
        $names = $this->argumentType($call, 2, $scope)->getConstantStrings();
        if (\count($collections) !== 1 || \count($names) !== 1) {
            return [];
        }

        $variables = $this->resolver->resolve($collections[0]->getValue());
        if (!$variables instanceof Type || !$variables->hasOffsetValueType($names[0])->no()) {
            return [];
        }

        // Assigned on another path: not provided means null, as at runtime
        if ($this->argumentType($call, 3, $scope)->isTrue()->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Variable "%s" is not defined: it is neither an argument nor provided by %s::getAdditionalVariables().',
                $names[0]->getValue(),
                $collections[0]->getValue(),
            ))->identifier('variable.undefined')->build(),
        ];
    }
}
