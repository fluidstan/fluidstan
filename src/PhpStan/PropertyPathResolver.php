<?php

declare(strict_types=1);

namespace FluidStan\PhpStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use Psr\Container\ContainerInterface;

/**
 * Resolves one segment of a Fluid property path the way StandardVariableProvider::getByPath()
 * does at runtime: array key, ArrayAccess, PSR container, getter, isser, hasser, public property.
 */
final class PropertyPathResolver
{
    public function resolve(Type $subject, string $segment, Scope $scope): PropertyPathResolution
    {
        $types = [];
        $errors = [];
        foreach (self::members($subject) as $member) {
            $resolution = $this->resolveMember($member, $segment, $scope);
            $types[] = $resolution->type;
            foreach ($resolution->errors as $error) {
                $errors[] = $error;
            }
        }

        return new PropertyPathResolution($types === [] ? new MixedType() : TypeCombinator::union(...$types), $errors);
    }

    private function resolveMember(Type $type, string $segment, Scope $scope): PropertyPathResolution
    {
        if ($type instanceof MixedType) {
            return new PropertyPathResolution(new MixedType(), []);
        }

        if ($type->isNull()->yes()) {
            // Fluid silently yields null for paths through null; that is intended null-safe navigation.
            return new PropertyPathResolution(new NullType(), []);
        }

        $key = ctype_digit($segment) ? new ConstantIntegerType((int) $segment) : new ConstantStringType($segment);

        if ($type->isArray()->yes()) {
            $hasOffset = $type->hasOffsetValueType($key);
            if ($hasOffset->no()) {
                return new PropertyPathResolution(new NullType(), [\sprintf('Key "%s" does not exist on %s.', $segment, $type->describe(VerbosityLevel::value()))]);
            }

            return new PropertyPathResolution($type->getOffsetValueType($key), []);
        }

        if ($type->isObject()->yes()) {
            if ((new ObjectType(\ArrayAccess::class))->isSuperTypeOf($type)->yes()) {
                return new PropertyPathResolution($type->getOffsetValueType($key), []);
            }

            if ((new ObjectType(ContainerInterface::class))->isSuperTypeOf($type)->yes()) {
                return new PropertyPathResolution(new MixedType(), []);
            }

            if ($type->getObjectClassNames() === []) {
                return new PropertyPathResolution(new MixedType(), []);
            }

            foreach (['get', 'is', 'has'] as $prefix) {
                $methodName = $prefix . ucfirst($segment);
                if ($type->hasMethod($methodName)->yes()) {
                    $method = $type->getMethod($methodName, $scope);
                    if (!$method->isPublic()) {
                        return new PropertyPathResolution(new NullType(), [\sprintf('Method %s::%s() is not public.', $type->describe(VerbosityLevel::value()), $methodName)]);
                    }

                    $returnType = ParametersAcceptorSelector::selectFromArgs($scope, [], $method->getVariants(), $method->getNamedArgumentsVariants())->getReturnType();
                    return new PropertyPathResolution($returnType, []);
                }
            }

            if ($type->hasInstanceProperty($segment)->yes()) {
                $property = $type->getInstanceProperty($segment, $scope);
                if (!$property->isPublic()) {
                    return new PropertyPathResolution(new NullType(), [\sprintf('Property %s::$%s is not public.', $type->describe(VerbosityLevel::value()), $segment)]);
                }

                return new PropertyPathResolution($property->getReadableType(), []);
            }

            return new PropertyPathResolution(new NullType(), [\sprintf(
                'Property "%s" does not exist on %s (no getter, isser, hasser or public property).',
                $segment,
                $type->describe(VerbosityLevel::value()),
            )]);
        }

        if ($type->isScalar()->yes()) {
            return new PropertyPathResolution(new NullType(), [\sprintf('Cannot access "%s" on %s.', $segment, $type->describe(VerbosityLevel::value()))]);
        }

        return new PropertyPathResolution(new MixedType(), []);
    }

    /**
     * @return list<Type>
     */
    public static function members(Type $type): array
    {
        return $type instanceof UnionType ? $type->getTypes() : [$type];
    }
}
