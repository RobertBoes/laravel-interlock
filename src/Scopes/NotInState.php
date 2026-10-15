<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A parent state stands for every state nested within it.
 */
final readonly class NotInState
{
    private InState $inState;

    /**
     * @param  BackedEnum|list<BackedEnum>  $states
     */
    public function __construct(BackedEnum|array $states, ?string $stateAttribute = null)
    {
        $this->inState = new InState($states, $stateAttribute);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>|Relation<TModel, *, *>  $query
     */
    public function __invoke(Builder|Relation $query): void
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        $builder->whereNot(($this->inState)(...));
    }
}
