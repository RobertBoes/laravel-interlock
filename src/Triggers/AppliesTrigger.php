<?php

namespace RobertBoes\Interlock\Triggers;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Scopes\CanTransition;
use Throwable;

/**
 * What a trigger's jobs share: finding the models that match the trigger, and applying its transition to one of them.
 *
 * @property-read class-string<Model> $model
 * @property-read string $trigger
 */
trait AppliesTrigger
{
    /**
     * @param  Closure(Builder<Model>): mixed  $condition
     * @return Builder<Model>
     */
    private function candidates(Closure $condition): Builder
    {
        return (new $this->model())->newQuery()
            ->tap(new CanTransition($this->trigger))
            ->where(fn(Builder $query) => $condition($query));
    }

    /**
     * A model that changed since it was loaded, or whose guard fails, is an expected outcome and is skipped quietly. Any
     * other failure is reported. Either way the run carries on, and the model is picked up again on a later run while
     * it still matches, so there are no retries (ADR 0005).
     */
    private function apply(Model $candidate, ExceptionHandler $exceptions): void
    {
        // The registry only lists models that use HasStateMachines.
        if (! method_exists($candidate, 'interlockApplyTrigger')) {
            throw InvalidDefinitionException::modelWithoutHasStateMachines($candidate::class);
        }

        try {
            $candidate->interlockApplyTrigger($this->trigger);
        } catch (StaleStateException|FailedGuardException) {
            return;
        } catch (Throwable $exception) {
            $exceptions->report($exception);
        }
    }
}
