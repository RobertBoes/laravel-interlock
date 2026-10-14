<?php

namespace RobertBoes\Interlock\Support;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionFunctionAbstract;

/**
 * @internal
 */
final class PhpAttribute
{
    /**
     * The first attribute of the class declared on the reflected class, method or constant, or null when it has none.
     *
     * @template TAttribute of object
     *
     * @param  ReflectionClass<*>|ReflectionClassConstant|ReflectionFunctionAbstract  $reflector
     * @param  class-string<TAttribute>  $attribute
     * @return TAttribute|null
     */
    public static function of(ReflectionClass|ReflectionClassConstant|ReflectionFunctionAbstract $reflector, string $attribute): ?object
    {
        return ($reflector->getAttributes($attribute)[0] ?? null)?->newInstance();
    }
}
