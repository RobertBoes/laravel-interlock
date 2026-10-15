<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\InvalidStateQueryException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\GraphRepository;

/**
 * @internal
 */
final readonly class MachineToQuery
{
    /**
     * Found from the state attribute when one is given, or else from the enum of the states.
     *
     * @param  list<BackedEnum>  $states
     */
    public static function of(Model $model, array $states, ?string $stateAttribute): CompiledMachine
    {
        $machines = Container::getInstance()->make(GraphRepository::class)->for($model)->machines;
        $stateAttributes = array_keys($machines);

        if ($machines === []) {
            throw InvalidStateQueryException::noStateMachines($model);
        }

        if ($stateAttribute !== null) {
            return $machines[$stateAttribute]
                ?? throw InvalidStateQueryException::noStateMachineOn($model, $stateAttribute, $stateAttributes);
        }

        if ($states === []) {
            return count($machines) === 1
                ? array_values($machines)[0]
                : throw InvalidStateQueryException::severalStateMachines($model, $stateAttributes);
        }

        $enum = $states[0]::class;
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

    /**
     * A parent state stands for every state nested within it.
     *
     * @param  list<BackedEnum>  $states
     * @return list<int|string>
     */
    public static function leafValues(Model $model, CompiledMachine $machine, array $states): array
    {
        $leafStates = [];

        foreach ($states as $state) {
            if (! $state instanceof $machine->states->enum) {
                throw InvalidStateQueryException::stateOfAnotherEnum($model, $machine->stateAttribute, $machine->states->enum, $state);
            }

            array_push($leafStates, ...$machine->states->leavesWithin($state));
        }

        return array_map(fn(BackedEnum $state) => $state->value, $leafStates);
    }
}
