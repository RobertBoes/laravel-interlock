<?php

namespace RobertBoes\Interlock\Triggers;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Exceptions\UnauthorisedTransitionException;
use RobertBoes\Interlock\Exceptions\UnauthorisedTriggerException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Scopes\CanTransition;
use Throwable;

/**
 * What a trigger's jobs share: finding the models that match the trigger, and applying its transition to one of them.
 *
 * @property-read class-string<Model> $model
 * @property-read string $trigger
 * @property int $timeout
 */
trait AppliesTrigger
{
    /**
     * Puts the job on the connection and queue `interlock.triggers` names, or the app's defaults when it names none,
     * with the timeout it sets.
     */
    private function queueAsConfigured(): void
    {
        $config = Container::getInstance()->make('config');

        $this->onConnection($config->get('interlock.triggers.connection'));
        $this->onQueue($config->get('interlock.triggers.queue'));
        $this->timeout = $config->integer('interlock.triggers.timeout', 60);
    }

    /**
     * The transition the job applies, or null when it no longer exists: a job queued before a deploy may name a
     * transition that was renamed or removed since, which, like a trigger that was removed, leaves nothing to do.
     */
    private function transition(GraphRepository $graphs): ?CompiledTransition
    {
        try {
            return $graphs->for(new $this->model())->transition($this->trigger);
        } catch (UnknownTransitionException) {
            return null;
        }
    }

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
     * Applies the transition to the candidates in order, until the deadline passes.
     *
     * @param  Collection<int, Model>  $candidates
     * @return int how many it got to, from the first
     */
    private function applyUntil(Deadline $deadline, Collection $candidates, ExceptionHandler $exceptions): int
    {
        $applied = 0;

        foreach ($candidates as $candidate) {
            if ($deadline->passed()) {
                break;
            }

            $this->apply($candidate, $exceptions);
            $applied++;
        }

        return $applied;
    }

    /**
     * A model that changed since it was loaded, or whose guard fails, is an expected outcome and is skipped quietly. Any
     * other failure is reported. Either way the run carries on, and the model is picked up again on a later run while
     * it still matches, so there are no retries (ADR 0005). A denial is reported wrapped, as the exception handler
     * drops authorisation exceptions.
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
        } catch (UnauthorisedTransitionException $denial) {
            $exceptions->report(UnauthorisedTriggerException::denied($candidate, $this->trigger, $denial));
        } catch (Throwable $exception) {
            $exceptions->report($exception);
        }
    }
}
