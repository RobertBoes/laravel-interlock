<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Triggers\RunTrigger;
use RobertBoes\Interlock\Triggers\TriggerMonitor;
use RobertBoes\Interlock\Triggers\TriggerStatus;
use Throwable;

/**
 * Only dispatches, so a large backlog never slows the scheduler down: the jobs do the querying.
 */
final class TriggersCommand extends Command
{
    protected $signature = 'interlock:triggers
        {--status : Show the models matching each trigger, its last run and whether it is overdue, instead of dispatching}';

    protected $description = 'Dispatch one job per trigger, to apply each triggered transition to the models that match';

    public function handle(ModelRegistry $registry, GraphRepository $graphs, Dispatcher $bus, Cache $cache, ExceptionHandler $exceptions, TriggerMonitor $monitor): int
    {
        if ($this->option('status')) {
            $this->status($monitor);

            return self::SUCCESS;
        }

        $allDispatched = true;

        foreach (array_keys($registry->models()) as $model) {
            foreach ($graphs->for(new $model())->transitions() as $transition) {
                if ($transition->trigger !== null) {
                    $allDispatched = $this->dispatch(new RunTrigger($model, $transition->name), $bus, new UniqueLock($cache), $exceptions) && $allDispatched;
                }
            }
        }

        return $allDispatched ? self::SUCCESS : self::FAILURE;
    }

    private function status(TriggerMonitor $monitor): void
    {
        $this->table(
            ['Model', 'Transition', 'Matching', 'Last run', 'Matching since', 'Overdue'],
            array_map(fn(TriggerStatus $status) => [
                $status->model,
                $status->transition,
                $status->matching,
                $status->lastRunAt?->toDateTimeString() ?? 'never',
                $status->matchingSince?->toDateTimeString() ?? '-',
                $status->overdue ? 'yes' : 'no',
            ], $monitor->statuses()),
        );
    }

    /**
     * Takes the unique lock as dispatch() would; the queue releases it once the job has run. A failed dispatch releases
     * it at once and is reported rather than thrown, so the triggers after it are still dispatched.
     *
     * @return bool false when the dispatch failed
     */
    private function dispatch(RunTrigger $job, Dispatcher $bus, UniqueLock $lock, ExceptionHandler $exceptions): bool
    {
        if (! $lock->acquire($job)) {
            return true;
        }

        try {
            $bus->dispatch($job);
        } catch (Throwable $exception) {
            $exceptions->report($exception);
            $lock->release($job);

            return false;
        }

        return true;
    }
}
