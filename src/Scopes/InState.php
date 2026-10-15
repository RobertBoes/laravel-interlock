<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RobertBoes\Interlock\Exceptions\InvalidStateQueryException;

/**
 * A parent state stands for every state nested within it.
 */
final readonly class InState
{
    /** @var non-empty-list<BackedEnum> */
    private array $states;

    /**
     * @param  BackedEnum|list<BackedEnum>  $states
     */
    public function __construct(BackedEnum|array $states, private ?string $stateAttribute = null)
    {
        $states = is_array($states) ? $states : [$states];

        if ($states === []) {
            throw InvalidStateQueryException::noStates();
        }

        $this->states = $states;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>|Relation<TModel, *, *>  $query
     */
    public function __invoke(Builder|Relation $query): void
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        $model = $builder->getModel();
        $machine = MachineToQuery::of($model, $this->states, $this->stateAttribute);

        $builder->whereIn(
            $builder->qualifyColumn($machine->stateAttribute),
            MachineToQuery::leafValues($model, $machine, $this->states),
        );
    }
}
