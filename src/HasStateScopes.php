<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Scopes\InFromStateOf;
use RobertBoes\Interlock\Scopes\InState;
use RobertBoes\Interlock\Scopes\NotInState;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasStateScopes
{
    /**
     * @param  Builder<static>  $query
     * @param  BackedEnum|list<BackedEnum>  $states
     */
    public function scopeWhereInState(Builder $query, BackedEnum|array $states, ?string $stateAttribute = null): void
    {
        $query->tap(new InState($states, $stateAttribute));
    }

    /**
     * @param  Builder<static>  $query
     * @param  BackedEnum|list<BackedEnum>  $states
     */
    public function scopeWhereNotInState(Builder $query, BackedEnum|array $states, ?string $stateAttribute = null): void
    {
        $query->tap(new NotInState($states, $stateAttribute));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWhereInFromStateOf(Builder $query, string $transition): void
    {
        $query->tap(new InFromStateOf($transition));
    }
}
