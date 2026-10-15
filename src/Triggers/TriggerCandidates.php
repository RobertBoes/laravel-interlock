<?php

namespace RobertBoes\Interlock\Triggers;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Scopes\InFromStateOf;

final class TriggerCandidates
{
    /**
     * @param  class-string<Model>  $model
     * @param  Closure(Builder<Model>): mixed  $condition
     * @return Builder<Model>
     */
    public static function query(string $model, string $trigger, Closure $condition): Builder
    {
        return (new $model())->newQuery()
            ->tap(new InFromStateOf($trigger))
            ->where(fn(Builder $query) => $condition($query));
    }
}
