<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\RejectsDirectWrites;

final class MissingBuilderTraitException extends LogicException implements InterlockException
{
    /**
     * @param  Builder<*>  $builder
     */
    public static function on(Model $model, Builder $builder): self
    {
        return new self(sprintf(
            '%s is interlocked, but its Eloquent builder %s does not use %s, so mass writes through it would skip Interlock. Add `use \\%s;` to %s.',
            $model::class,
            $builder::class,
            RejectsDirectWrites::class,
            RejectsDirectWrites::class,
            $builder::class,
        ));
    }
}
