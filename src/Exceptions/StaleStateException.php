<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use RobertBoes\Interlock\Graph\CompiledTransition;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The stored state changed after the model was loaded, so the transition did not apply. It renders as 409 Conflict:
 * reload and try again.
 */
final class StaleStateException extends RuntimeException implements InterlockException, HttpExceptionInterface
{
    public static function storedStateChanged(Model $model, CompiledTransition $transition, BackedEnum $loadedState, int|string|null $storedState): self
    {
        return new self(sprintf(
            'Cannot apply transition [%s] to %s [%s]: its [%s] was [%s] when it was loaded, but the stored [%s] is now [%s]. Reload the model and try again.',
            $transition->name,
            $model::class,
            $model->getKey(),
            $transition->stateAttribute,
            $loadedState->value,
            $transition->stateAttribute,
            $storedState ?? 'null',
        ));
    }

    public static function rowDeleted(Model $model, CompiledTransition $transition): self
    {
        return new self(sprintf(
            'Cannot apply transition [%s] to %s [%s]: its row no longer exists.',
            $transition->name,
            $model::class,
            $model->getKey(),
        ));
    }

    public function getStatusCode(): int
    {
        return 409;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
