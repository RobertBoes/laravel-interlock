<?php

namespace RobertBoes\Interlock;

use Illuminate\Support\Str;

/**
 * Optional methods, found by name: authorise(), #[Guard] methods, a static trigger(), apply() and afterCommit().
 * availableTransitions() builds the class without its constructor, so authorise() must not read the input.
 */
abstract class Transition
{
    public static function name(): string
    {
        return Str::snake(class_basename(static::class));
    }
}
