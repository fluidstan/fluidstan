<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\PhpStan\PropertyPathResolver;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `{subject.segment}` must be resolvable on the subject's type.
 */
final class PropertyAccessRule extends FacadeCallRule
{
    public function __construct(private readonly PropertyPathResolver $resolver) {}

    protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array
    {
        if ($method !== 'get') {
            return [];
        }

        $segments = $this->argumentType($call, 1, $scope)->getConstantStrings();
        if (\count($segments) !== 1) {
            return [];
        }

        $errors = [];
        $resolution = $this->resolver->resolve($this->argumentType($call, 0, $scope), $segments[0]->getValue(), $scope);
        foreach ($resolution->errors as $message) {
            $errors[] = RuleErrorBuilder::message($message)->identifier('fluidstan.property.unresolvable')->build();
        }

        return $errors;
    }
}
