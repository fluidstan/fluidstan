<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use FluidStan\Runtime\F;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/**
 * Base for rules that inspect calls to the generated code's facade.
 *
 * @implements Rule<StaticCall>
 */
abstract class FacadeCallRule implements Rule
{
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return [];
        }

        if ($scope->resolveName($node->class) !== F::class) {
            return [];
        }

        return $this->processFacadeCall($node->name->toString(), $node, $scope);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    abstract protected function processFacadeCall(string $method, StaticCall $call, Scope $scope): array;

    protected function argumentType(StaticCall $call, int $index, Scope $scope): Type
    {
        $args = $call->getArgs();
        return isset($args[$index]) ? $scope->getType($args[$index]->value) : new MixedType();
    }
}
