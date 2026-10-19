<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\CompiledTransition;

/**
 * The transition is system-only and was applied or saved outside Interlock::asSystem().
 */
final class SystemOnlyTransitionException extends UnauthorizedTransitionException
{
    public static function outsideSystemScope(Model $model, CompiledTransition $transition): self
    {
        $message = sprintf('Only system code applies transition [%s]. Apply it inside Interlock::asSystem($reason, $callback). Job middleware such as RunsAsSystem only runs when the job is dispatched, not when handle() is called directly.', $transition->name);
        $exception = new self($message, null, $model, $transition->name);

        return $exception->setResponse(Response::deny($message))->withStatus(403);
    }
}
