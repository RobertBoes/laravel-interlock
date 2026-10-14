<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Limits a query to the models in none of the given states: `Order::query()->tap(new NotInState(OrderStatus::Preparing))`.
 *
 * A parent state stands for every state nested within it. The state attribute is found from the states' enum; pass it
 * as `attribute` when the model has several machines with states of that enum.
 */
final readonly class NotInState
{
    private InState $inState;

    /**
     * @param  BackedEnum|list<BackedEnum>  $states
     */
    public function __construct(BackedEnum|array $states, ?string $attribute = null)
    {
        $this->inState = new InState($states, $attribute);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function __invoke(Builder $query): void
    {
        $query->whereNot(($this->inState)(...));
    }
}
