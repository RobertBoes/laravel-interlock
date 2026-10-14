<?php

namespace RobertBoes\Interlock\Triggers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Scopes\CanTransition;
use Throwable;

/**
 * Applies one trigger's transition to every model that matches its condition. Unique per trigger, so a slow run and
 * the next one never process the same trigger at once, and never retried: what fails still matches on the next run.
 */
final class RunTrigger implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    /** Seconds after which the lock lapses, should a worker die without releasing it. */
    public int $uniqueFor = 3600;

    /**
     * @param  class-string<Model>  $model
     * @param  string  $trigger  the name of the trigger, which is the name of the transition it applies
     */
    public function __construct(
        public readonly string $model,
        public readonly string $trigger,
    ) {}

    public function uniqueId(): string
    {
        return $this->model . ':' . $this->trigger;
    }

    public function handle(GraphRepository $graphs, Repository $config, ExceptionHandler $exceptions): void
    {
        $model = new $this->model();
        $condition = $graphs->for($model)->transition($this->trigger)->trigger;

        if ($condition === null) {
            return;
        }

        $model->newQuery()
            ->tap(new CanTransition($this->trigger))
            ->where(fn(Builder $query) => $condition($query))
            ->chunkById($config->get('interlock.triggers.chunk_size', 100), function (Collection $candidates) use ($exceptions) {
                foreach ($candidates as $candidate) {
                    $this->apply($candidate, $exceptions);
                }
            });
    }

    /**
     * A model that changed since it was loaded, or whose guard fails, is an expected outcome and is skipped quietly. Any
     * other failure is reported. Either way the run carries on, and the model is picked up again on the next run while
     * it still matches, so there are no retries (ADR 0005).
     */
    private function apply(Model $candidate, ExceptionHandler $exceptions): void
    {
        // Every interlocked model uses HasStateMachines.
        if (! method_exists($candidate, 'interlockApplyTrigger')) {
            return;
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
