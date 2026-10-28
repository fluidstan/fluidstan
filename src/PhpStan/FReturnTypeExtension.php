<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use FluidStan\Runtime\F;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Return types of the FluidStan\Runtime\F facade methods used in generated code.
 */
final readonly class FReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    public function __construct(
        private PropertyPathResolver $resolver,
        private AdditionalVariablesResolver $additionalVariables,
        private ReflectionProvider $reflectionProvider,
        private bool $strict = false,
    ) {}

    public function getClass(): string
    {
        return F::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return \in_array($methodReflection->getName(), ['get', 'getDynamic', 'each', 'valueOf', 'viewHelper', 'cast', 'arg', 'additionalVariable', 'operand', 'bool'], true);
    }

    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        $args = $methodCall->getArgs();
        $argType = static fn(int $index): Type => isset($args[$index]) ? $scope->getType($args[$index]->value) : new MixedType();

        switch ($methodReflection->getName()) {
            case 'get':
                $segments = $argType(1)->getConstantStrings();
                if (\count($segments) !== 1) {
                    return new MixedType();
                }

                return $this->resolver->resolve($argType(0), $segments[0]->getValue(), $scope)->type;

            case 'getDynamic':
                $subject = TypeCombinator::removeNull($argType(0));
                if ($subject->isArray()->yes() || (new ObjectType(\ArrayAccess::class))->isSuperTypeOf($subject)->yes()) {
                    return TypeCombinator::addNull($subject->getOffsetValueType($argType(1)));
                }

                return new MixedType();

            case 'each':
                $subject = TypeCombinator::removeNull($argType(0));
                if ($subject->isIterable()->yes()) {
                    return $subject;
                }

                return new IterableType(new MixedType(), new MixedType());

            case 'valueOf':
                $subject = TypeCombinator::removeNull($argType(0));
                return $subject->isIterable()->yes() ? $subject->getIterableValueType() : new MixedType();

            case 'viewHelper':
                foreach ($argType(0)->getConstantStrings() as $className) {
                    if (!$this->reflectionProvider->hasClass($className->getValue())) {
                        continue;
                    }

                    $class = $this->reflectionProvider->getClass($className->getValue());
                    if (!$class->hasNativeMethod('render')) {
                        continue;
                    }

                    $render = $class->getNativeMethod('render');

                    return ParametersAcceptorSelector::selectFromArgs($scope, [], $render->getVariants(), $render->getNamedArgumentsVariants())->getReturnType();
                }

                return new MixedType();

            case 'cast':
                $targets = $argType(1)->getConstantStrings();
                $target = \count($targets) === 1 ? $targets[0]->getValue() : '';
                return match ($target) {
                    'integer' => new IntegerType(),
                    'boolean' => new BooleanType(),
                    'string' => new StringType(),
                    'float' => new FloatType(),
                    'array' => new ArrayType(new MixedType(), new MixedType()),
                    'DateTime' => new ObjectType(\DateTime::class),
                    default => new MixedType(),
                };

            case 'arg':
                return $argType(1);

            case 'operand':
                return FluidConversion::ofOperand($argType(0));

            case 'bool':
                // In strict mode a condition decided by the types is a constant, which PHPStan reports as always true or false
                if (!$this->strict) {
                    return new BooleanType();
                }

                $truthiness = Truthiness::of($argType(0));
                return $truthiness->maybe() ? new BooleanType() : new ConstantBooleanType($truthiness->yes());

            case 'additionalVariable':
                $collections = $argType(0)->getConstantStrings();
                $names = $argType(2)->getConstantStrings();
                if (\count($collections) !== 1 || \count($names) !== 1) {
                    return new MixedType();
                }

                $variables = $this->additionalVariables->resolve($collections[0]->getValue());
                if (!$variables instanceof Type || !$variables->isArray()->yes()) {
                    return new MixedType();
                }

                if ($variables->hasOffsetValueType($names[0])->no()) {
                    return $argType(3)->isTrue()->yes() ? new NullType() : new MixedType();
                }

                return $variables->getOffsetValueType($names[0]);
        }

        return null;
    }
}
