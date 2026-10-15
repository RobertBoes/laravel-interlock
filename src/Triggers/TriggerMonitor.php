<?php

namespace RobertBoes\Interlock\Triggers;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;

final readonly class TriggerMonitor
{
    public function __construct(
        private ModelRegistry $registry,
        private GraphRepository $graphs,
        private Cache $cache,
        private Repository $config,
    ) {}

    /**
     * @return list<TriggerStatus>
     */
    public function statuses(): array
    {
        $threshold = Date::now()->subMinutes($this->config->integer('interlock.triggers.overdue_after', 15));
        $statuses = [];

        foreach (array_keys($this->registry->models()) as $model) {
            foreach ($this->graphs->for(new $model())->transitions() as $transition) {
                if ($transition->trigger !== null) {
                    $statuses[] = $this->status($model, $transition, $transition->trigger, $threshold);
                }
            }
        }

        return $statuses;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(Builder<Model>): mixed  $condition
     */
    private function status(string $model, CompiledTransition $transition, Closure $condition, CarbonInterface $threshold): TriggerStatus
    {
        $runs = new TriggerRuns($this->cache, $model, $transition->name);
        $matching = TriggerCandidates::query($model, $transition->name, $condition)->count();
        $lastRunAt = $runs->lastRunAt();
        $matchingSince = $runs->matchingSince();

        $overdue = $matching > 0 && (
            $lastRunAt === null
            || $lastRunAt->lessThan($threshold)
            || $matchingSince?->lessThan($threshold) === true
        );

        return new TriggerStatus($model, $transition->name, $matching, $lastRunAt, $matchingSince, $overdue);
    }
}
