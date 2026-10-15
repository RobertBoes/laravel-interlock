<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledMachine;

final class InvalidStateQueryException extends LogicException implements InterlockException
{
    public static function noStates(): self
    {
        return new self('Pass at least one state to query by.');
    }

    public static function noDuration(): self
    {
        return new self('Pass how long the models have been in the state: minutes, hours, days or an interval.');
    }

    public static function noEnteredAtColumn(Model $model, CompiledMachine $machine): self
    {
        return new self(sprintf(
            '%s does not record when its [%s] entered a state. Declare the column with enteredAt: on its #[UseStateMachine] to query how long it has been in a state.',
            $model::class,
            $machine->stateAttribute,
        ));
    }

    /**
     * @param  list<string>  $stateAttributes
     */
    public static function severalStateMachines(Model $model, array $stateAttributes): self
    {
        return new self(sprintf(
            '%s has several state machines, on [%s]. Pass the state attribute to query as the stateAttribute argument.',
            $model::class,
            implode(', ', $stateAttributes),
        ));
    }

    public static function noStateMachines(Model $model): self
    {
        return new self(sprintf(
            '%s has no state machines. Add #[UseStateMachine] and the HasStateMachines trait to query it by state.',
            $model::class,
        ));
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  list<string>  $stateAttributes
     */
    public static function noStateMachineWithStatesOf(Model $model, string $enum, array $stateAttributes): self
    {
        return new self(sprintf(
            '%s has no state machine with states of %s. Its state attributes are [%s].',
            $model::class,
            $enum,
            implode(', ', $stateAttributes),
        ));
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  list<string>  $matchingStateAttributes
     */
    public static function severalStateMachinesWithStatesOf(Model $model, string $enum, array $matchingStateAttributes): self
    {
        return new self(sprintf(
            '%s has several state machines with states of %s, on [%s]. Pass the state attribute to query as the attribute argument.',
            $model::class,
            $enum,
            implode(', ', $matchingStateAttributes),
        ));
    }

    /**
     * @param  list<string>  $stateAttributes
     */
    public static function noStateMachineOn(Model $model, string $attribute, array $stateAttributes): self
    {
        return new self(sprintf(
            '%s has no state machine on [%s]. Its state attributes are [%s].',
            $model::class,
            $attribute,
            implode(', ', $stateAttributes),
        ));
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function stateOfAnotherEnum(Model $model, string $stateAttribute, string $enum, BackedEnum $state): self
    {
        return new self(sprintf(
            'The [%s] of %s holds states of %s, not [%s] of %s.',
            $stateAttribute,
            $model::class,
            $enum,
            $state->value,
            $state::class,
        ));
    }
}
