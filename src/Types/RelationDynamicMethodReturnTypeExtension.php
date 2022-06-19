<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan\Types;

use Illuminate\Database\Eloquent\Model;
use NunoMaduro\Larastan\Methods\BuilderHelper;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeWithClassName;

class RelationDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /** @var BuilderHelper */
    private $builderHelper;

    public function __construct(BuilderHelper $builderHelper)
    {
        $this->builderHelper = $builderHelper;
    }

    public function getClass(): string
    {
        return Model::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), [
            'hasOne', 'hasOneThrough', 'morphOne',
            'belongsTo', 'morphTo',
            'hasMany', 'hasManyThrough', 'morphMany',
            'belongsToMany', 'morphToMany', 'morphedByMany',
        ], true);
    }

    /**
     * @throws ShouldNotHappenException
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        $methodName = $methodReflection->getName();
        /** @var FunctionVariant $functionVariant */
        $functionVariant = ParametersAcceptorSelector::selectSingle($methodReflection->getVariants());
        $returnType = $functionVariant->getReturnType();

        if (! $returnType instanceof ObjectType) {
            return $returnType;
        }

        if (
            count($methodCall->getArgs()) < 1 || (
                in_array($methodName, ['hasOneThrough', 'hasManyThrough']) &&
                count($methodCall->getArgs()) < 2
            )
        ) {
            return $returnType;
        }

        $templateTypes = [];

        $relatedModelClassArgType = $scope->getType($methodCall->getArgs()[0]->value);
        if (! $relatedModelClassArgType instanceof ConstantStringType) {
            return $returnType;
        }
        $relatedModelClassName = $relatedModelClassArgType->getValue();
        $templateTypes[] = new ObjectType($relatedModelClassName);

        $callingObjectType = $scope->getType($methodCall->var);
        if (! $callingObjectType instanceof TypeWithClassName) {
            return $returnType;
        }
        $declaringModelClassName = $callingObjectType->getClassName();
        $templateTypes[] = new ObjectType($declaringModelClassName);

        if (in_array($methodName, ['hasOneThrough', 'hasManyThrough'])) {
            $intermediateModelClassArgType = $scope->getType($methodCall->getArgs()[1]->value);
            if (! $intermediateModelClassArgType instanceof ConstantStringType) {
                return $returnType;
            }
            $intermediateModelClassName = $intermediateModelClassArgType->getValue();
            $templateTypes[] = new ObjectType($intermediateModelClassName);
        }

        // Work-around for a Laravel bug
        // Opposed to other `HasOne...` and `HasMany...` methods,
        // `HasOneThrough` and `HasManyThrough` do not extend a common
        // `HasOneOrManyThrough` base class, but `HasOneThrough` directly
        // extends `HasManyThrough`.
        // This does not only violate Liskov's Substitution Principle but also
        // has the unfortunate side effect that `HasManyThrough` cannot
        // bind the template parameter `TResult` to a Collection, but needs
        // to keep it unbound for `HasOneThrough` to overwrite it.
        // Hence, if `HasManyTrough` is used directly, we must bind the
        // fourth template parameter `TResult` here.
        if ($methodName === 'hasManyThrough') {
            $collectionClassName = $this->builderHelper->determineCollectionClassName($relatedModelClassName);
            $templateTypes[] = new GenericObjectType($collectionClassName, [new ObjectType($relatedModelClassName)]);
        }

        return new GenericObjectType($returnType->getClassName(), $templateTypes);
    }
}
