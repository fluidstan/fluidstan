<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\PhpStan\PropertyPathResolver;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * `{subject.segment}` must be resolvable on the subject's type. Fluid yields null for a path through
 * null without complaint; in strict mode a subject that may be null is reported.
 */
final class PropertyAccessRule extends FacadeCallRule
{
    public function __construct(
        private readonly PropertyPathResolver $resolver,
        private readonly bool $strict = false,
    ) {}

    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if ($method !== 'get' && $method !== 'getDynamic') {
            return [];
        }

        $errors = [];
        $subject = $this->argumentType($call, 0, $scope);
        $segment = null;
        if ($method === 'get') {
            $segments = $this->argumentType($call, 1, $scope)->getConstantStrings();
            if (\count($segments) !== 1) {
                return [];
            }

            $segment = $segments[0]->getValue();
            foreach ($this->resolver->resolve($subject, $segment, $scope)->errors as $message) {
                $errors[] = RuleErrorBuilder::message($message)->identifier('fluidstan.property.unresolvable')->build();
            }
        }

        // A dynamic segment such as {item.{key}} cannot be resolved, but its subject may be null just the same
        if ($this->strict && !$subject instanceof MixedType && TypeCombinator::containsNull($subject)) {
            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Accessing %s on %s: Fluid yields null when the subject is null.',
                $segment === null ? 'a dynamic key' : '"' . $segment . '"',
                $subject->describe(VerbosityLevel::typeOnly()),
            ))->identifier('fluidstan.property.nullable')->build();
        }

        return $errors;
    }
}
