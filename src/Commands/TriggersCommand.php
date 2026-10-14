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
use Throwable;

/**
 * Scheduled every minute by default. It only dispatches, so a large backlog never slows the scheduler down: the jobs do the querying.
 */
final class TriggersCommand extends Command
{
    protected $signature = 'interlock:triggers';

    protected $description = 'Dispatch one job per trigger, to apply each triggered transition to the models that match';

    public function handle(ModelRegistry $registry, GraphRepository $graphs, Dispatcher $bus, Cache $cache, ExceptionHandler $exceptions): int
    {
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

    /**
     * The lock is taken here, as dispatch() does for a unique job, and released by the queue once the job has run. A
     * dispatch that fails releases it at once, so the next run can dispatch the trigger again, and is reported rather
     * than thrown, so the triggers after it are still dispatched.
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
