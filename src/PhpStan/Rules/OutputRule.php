<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\PhpStan\PropertyPathResolver;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\VerbosityLevel;

/**
 * Values rendered into the output are cast to string by Fluid, which throws for arrays and for
 * objects without __toString(). A bool renders as "1" or as nothing; strict mode reports it.
 */
final class OutputRule extends FacadeCallRule
{
    public function __construct(private readonly bool $strict = false) {}

    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if ($method !== 'output' && $method !== 'concat') {
            return [];
        }

        $errors = [];
        foreach ($call->getArgs() as $arg) {
            $type = $scope->getType($arg->value);
            foreach (PropertyPathResolver::members($type) as $member) {
                if ($member instanceof MixedType || $member->isNull()->yes()) {
                    continue;
                }

                if ($member->isArray()->yes()) {
                    $errors[] = RuleErrorBuilder::message(\sprintf('Cannot render %s: arrays cannot be cast to string.', $member->describe(VerbosityLevel::typeOnly())))
                        ->identifier('fluidstan.output.array')
                        ->build();
                } elseif ($member->isObject()->yes() && $member->toString() instanceof ErrorType) {
                    $errors[] = RuleErrorBuilder::message(\sprintf('Cannot render %s: object without __toString().', $member->describe(VerbosityLevel::typeOnly())))
                        ->identifier('fluidstan.output.object')
                        ->build();
                } elseif ($this->strict && $member->isBoolean()->yes()) {
                    $errors[] = RuleErrorBuilder::message(\sprintf('Rendering %s prints "1" or nothing.', $member->describe(VerbosityLevel::typeOnly())))
                        ->identifier('fluidstan.output.bool')
                        ->build();
                }
            }
        }

        return $errors;
    }
}
