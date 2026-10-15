<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\CompiledTransition;

/**
 * The transition has a trigger and declares no authorization, so no one may apply it by hand.
 */
final class TriggerOnlyTransitionException extends UnauthorizedTransitionException
{
    public static function appliedByHand(Model $model, CompiledTransition $transition): self
    {
        $message = sprintf('Only its trigger applies transition [%s].', $transition->name);
        $exception = new self($message, null, $model, $transition->name);

        return $exception->setResponse(Response::deny($message))->withStatus(403);
    }
}
