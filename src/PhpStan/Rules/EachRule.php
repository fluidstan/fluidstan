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
 * f:for accepts arrays and Traversables (and null, which renders nothing).
 */
final class EachRule extends FacadeCallRule
{
    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if ($method !== 'each' && $method !== 'valueOf') {
            return [];
        }

        $errors = [];
        foreach (PropertyPathResolver::members($this->argumentType($call, 0, $scope)) as $member) {
            if ($member instanceof MixedType || $member->isNull()->yes()) {
                continue;
            }

            if (!$member->isIterable()->yes()) {
                $errors[] = RuleErrorBuilder::message(\sprintf('Cannot iterate over %s: f:for expects an array or Traversable.', $member->describe(VerbosityLevel::typeOnly())))
                    ->identifier('fluidstan.for.notIterable')
                    ->build();
            }
        }

        return $errors;
    }
}
