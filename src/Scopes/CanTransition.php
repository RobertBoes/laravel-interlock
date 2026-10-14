<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\GraphRepository;

/**
 * Limits a query to the models whose current state is a from-state of the named transition.
 *
 * It checks from-states only. Guards and authorisation are not evaluated, so applying the transition to a model this
 * finds can still fail; ask the model for its available transitions when that matters.
 */
final readonly class CanTransition
{
    public function __construct(private string $transition) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function __invoke(Builder $query): void
    {
        $transition = Container::getInstance()->make(GraphRepository::class)->for($query->getModel())->transition($this->transition);

        $query->whereIn(
            $query->qualifyColumn($transition->stateAttribute),
            array_map(fn(BackedEnum $state) => $state->value, $transition->leafFromStates),
        );
    }
}
