<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * The type of what a component collection's getAdditionalVariables() returns, without running it.
 *
 * PHPStan infers return types from bodies only for closures and trusts signatures otherwise, so a
 * collection that just returns a literal array has the return type "array". The method body is
 * analysed here the way PHPStan analyses a closure body: the types of its return statements are
 * combined. An array shape declared in the PHPDoc is authoritative.
 */
final class AdditionalVariablesResolver
{
    private const METHOD = 'getAdditionalVariables';

    /** @var array<string, Type> declaring class => return type */
    private array $cache = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly Parser $parser,
        private readonly NodeScopeResolver $nodeScopeResolver,
        private readonly ScopeFactory $scopeFactory,
    ) {}

    /**
     * @return Type|null null when the collection class or the method is unknown
     */
    public function resolve(string $collectionClass): ?Type
    {
        if (!$this->reflectionProvider->hasClass($collectionClass)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($collectionClass);
        if (!$class->hasNativeMethod(self::METHOD)) {
            return null;
        }

        $method = $class->getNativeMethod(self::METHOD);
        return $this->cache[$method->getDeclaringClass()->getName()] ??= $this->returnType($method);
    }

    private function returnType(ExtendedMethodReflection $method): Type
    {
        $variants = $method->getVariants();
        $declared = \count($variants) === 1 ? $variants[0]->getReturnType() : new MixedType();
        if ($declared->isConstantArray()->yes()) {
            return $declared;
        }

        return $this->inferFromBody($method) ?? $declared;
    }

    private function inferFromBody(ExtendedMethodReflection $method): ?Type
    {
        $file = $method->getDeclaringClass()->getFileName();
        if ($file === null || !is_file($file)) {
            return null;
        }

        $className = $method->getDeclaringClass()->getName();
        $types = [];
        $this->nodeScopeResolver->processNodes(
            $this->parser->parseFile($file),
            $this->scopeFactory->create(ScopeContext::create($file)),
            static function (Node $node, Scope $scope) use (&$types, $className): void {
                if (!$node instanceof Return_ || !$node->expr instanceof Expr || $scope->isInAnonymousFunction()) {
                    return;
                }

                $function = $scope->getFunction();
                if (!$function instanceof MethodReflection || $function->getName() !== self::METHOD || $scope->getClassReflection()?->getName() !== $className) {
                    return;
                }

                $types[] = $scope->getType($node->expr);
            },
        );

        return $types === [] ? null : TypeCombinator::union(...$types);
    }
}
