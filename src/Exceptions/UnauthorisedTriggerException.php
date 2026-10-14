<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Authorisation denied a trigger its transition. Reported in place of the UnauthorisedTransitionException it wraps,
 * which Laravel's exception handler never reports, being an authorisation exception: a trigger that is denied every
 * time would otherwise never fire, and nothing would say why.
 */
final class UnauthorisedTriggerException extends RuntimeException implements InterlockException
{
    public static function denied(Model $model, string $trigger, UnauthorisedTransitionException $denial): self
    {
        return new self(sprintf(
            'Trigger [%s] could not apply its transition to %s [%s]: authorisation denied it with "%s". A trigger applies its transition on behalf of no one, so the transition\'s authorisation must allow a null actor.',
            $trigger,
            $model::class,
            $model->getKey(),
            $denial->getMessage(),
        ), previous: $denial);
    }
}
