<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * ViewHelper and component arguments must satisfy the argument definition, as validated at
 * runtime by Fluid's StrictArgumentProcessor.
 */
final class ArgumentRule extends FacadeCallRule
{
    public function __construct(private readonly TypeStringResolver $typeStringResolver) {}

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
            $acceptedType = $this->typeStringResolver->resolve($declared);
        } catch (\Throwable) {
            return [RuleErrorBuilder::message(\sprintf('Argument "%s" of %s has an invalid type declaration "%s".', $names[0]->getValue(), $viewHelpers[0]->getValue(), $declared))
                ->identifier('fluidstan.argument.invalidType')
                ->build()];
        }

        if (!$required) {
            $acceptedType = TypeCombinator::addNull($acceptedType);
        }

        if ($acceptedType->accepts($valueType, true)->yes()) {
            return [];
        }

        return [RuleErrorBuilder::message(\sprintf(
            'Argument "%s" of %s expects %s, %s given.',
            $names[0]->getValue(),
            $viewHelpers[0]->getValue(),
            $acceptedType->describe(VerbosityLevel::typeOnly()),
            $valueType->describe(VerbosityLevel::typeOnly()),
        ))->identifier('fluidstan.argument.type')->build()];
    }
}
