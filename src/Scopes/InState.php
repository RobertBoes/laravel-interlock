<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\InvalidStateQueryException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\GraphRepository;

/**
 * Limits a query to the models in one of the given states: `Order::query()->tap(new InState(OrderStatus::Preparing))`.
 *
 * A parent state stands for every state nested within it. The state attribute is found from the states' enum; pass it
 * as `attribute` when the model has several machines with states of that enum.
 */
final readonly class InState
{
    /** @var non-empty-list<BackedEnum> */
    private array $states;

    /**
     * @param  BackedEnum|list<BackedEnum>  $states
     */
    public function __construct(BackedEnum|array $states, private ?string $attribute = null)
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
     * @param  Builder<TModel>  $query
     */
    public function __invoke(Builder $query): void
    {
        $model = $query->getModel();
        $machine = $this->machineToQuery($model);

        $leafStates = [];

        foreach ($this->states as $state) {
            if (! $state instanceof $machine->states->enum) {
                throw InvalidStateQueryException::stateOfAnotherEnum($model, $machine->stateAttribute, $machine->states->enum, $state);
            }

            array_push($leafStates, ...$machine->states->leavesWithin($state));
        }

        $query->whereIn(
            $query->qualifyColumn($machine->stateAttribute),
            array_map(fn(BackedEnum $state) => $state->value, $leafStates),
        );
    }

    private function machineToQuery(Model $model): CompiledMachine
    {
        $machines = Container::getInstance()->make(GraphRepository::class)->for($model)->machines;
        $stateAttributes = array_keys($machines);

        if ($machines === []) {
            throw InvalidStateQueryException::noStateMachines($model);
        }

        if ($this->attribute !== null) {
            return $machines[$this->attribute]
                ?? throw InvalidStateQueryException::noStateMachineOn($model, $this->attribute, $stateAttributes);
        }

        $enum = $this->states[0]::class;
        $matching = array_values(array_filter($machines, fn(CompiledMachine $machine) => $machine->states->enum === $enum));

        return match (count($matching)) {
            0 => throw InvalidStateQueryException::noStateMachineWithStatesOf($model, $enum, $stateAttributes),
            1 => $matching[0],
            default => throw InvalidStateQueryException::severalStateMachinesWithStatesOf(
                $model,
                $enum,
                array_map(fn(CompiledMachine $machine) => $machine->stateAttribute, $matching),
            ),
        };
    }
}
