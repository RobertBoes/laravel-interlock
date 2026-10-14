<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\StateMachine;

final class InvalidDefinitionException extends LogicException implements InterlockException
{
    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function missingTargetState(string $machine, string $transition): self
    {
        return new self(sprintf(
            'Transition [%s] in %s has no target state. Call ->to() with the one state it leads to.',
            $transition,
            $machine,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function missingFromStates(string $machine, string $transition): self
    {
        return new self(sprintf(
            'Transition [%s] in %s has no from-states. Call ->from() with the states it may start from.',
            $transition,
            $machine,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function stateAttributeNotCast(string $machine, Model $model, string $stateAttribute, BackedEnum $state): self
    {
        return new self(sprintf(
            '%s uses states of %s, so %s must cast [%s] to %s in its casts.',
            $machine,
            $state::class,
            $model::class,
            $stateAttribute,
            $state::class,
        ));
    }
}
