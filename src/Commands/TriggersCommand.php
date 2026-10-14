<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Triggers\RunTrigger;

/**
 * Scheduled every minute. It only dispatches, so a large backlog never slows the scheduler down: the jobs do the querying.
 */
final class TriggersCommand extends Command
{
    protected $signature = 'interlock:triggers';

    protected $description = 'Dispatch one job per trigger, to apply each triggered transition to the models that match';

    public function handle(ModelRegistry $registry, GraphRepository $graphs, Dispatcher $bus, Cache $cache): void
    {
        foreach (array_keys($registry->models()) as $model) {
            $graph = $graphs->for(new $model());

            foreach ($graph->machines as $machine) {
                foreach ($graph->transitionsOf($machine) as $transition) {
                    if ($transition->trigger === null) {
                        continue;
                    }

                    $job = new RunTrigger($model, $transition->name);

                    // Taken here, as dispatch() does for a unique job, and released by the queue once the job has run.
                    if ((new UniqueLock($cache))->acquire($job)) {
                        $bus->dispatch($job);
                    }
                }
            }
        }
    }
}
