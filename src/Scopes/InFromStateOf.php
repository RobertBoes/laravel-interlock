<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RobertBoes\Interlock\Graph\GraphRepository;

/**
 * Guards and authorization are not evaluated.
 */
final readonly class InFromStateOf
{
    public function __construct(private string $transition) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>|Relation<TModel, *, *>  $query
     */
    public function __invoke(Builder|Relation $query): void
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        $transition = Container::getInstance()->make(GraphRepository::class)->for($builder->getModel())->transition($this->transition);

        $builder->whereIn(
            $builder->qualifyColumn($transition->stateAttribute),
            array_map(fn(BackedEnum $state) => $state->value, $transition->leafFromStates),
        );
    }
}
