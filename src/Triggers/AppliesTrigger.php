<?php

namespace RobertBoes\Interlock\Triggers;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;
use Throwable;

/**
 * @property-read class-string<Model> $model
 * @property-read string $trigger
 * @property int $timeout
 */
trait AppliesTrigger
{
    use DetectsLostConnections;

    private function queueAsConfigured(): void
    {
        $config = Container::getInstance()->make('config');

        $this->onConnection($config->get('interlock.triggers.connection'));
        $this->onQueue($config->get('interlock.triggers.queue'));
        $this->timeout = $config->integer('interlock.triggers.timeout', 60);
    }

    /**
     * Null when the transition no longer exists: a job queued before a deploy may name one renamed or removed since.
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
        return TriggerCandidates::query($this->model, $this->trigger, $condition);
    }

    /**
     * @param  Collection<int, Model>  $candidates
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
     * No retries: a model that still matches is picked up again on a later run. Authorization is not checked, so it
     * never denies a trigger.
     *
     * A lost connection is rethrown to fail the job: every other candidate would fail too, reported once per model in a
     * run that counted as a success.
     */
    private function apply(Model $candidate, ExceptionHandler $exceptions): void
    {
        // A job queued before a deploy may name a model that has dropped HasStateMachines since.
        if (! method_exists($candidate, 'interlockApplyTrigger')) {
            throw InvalidDefinitionException::modelWithoutHasStateMachines($candidate::class);
        }

        try {
            $trigger = $this->trigger;
            (fn() => $this->interlockApplyTrigger($trigger))->call($candidate);
        } catch (StaleStateException|FailedGuardException) {
            return;
        } catch (Throwable $exception) {
            if ($this->causedByLostConnection($exception)) {
                throw $exception;
            }

            $exceptions->report($exception);
        }
    }
}
