<?php

namespace RobertBoes\Interlock\Triggers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\GraphRepository;

/**
 * Applies one trigger's transition to the models that match its condition, at most `interlock.triggers.max_per_run`
 * of them per run, and stops before its timeout. Unique per trigger, so a slow run and the next one never process the
 * same trigger at once, and never retried: what fails still matches on a later run. A trigger that fans out dispatches one job per chunk instead,
 * and does nothing while the chunk jobs of its last run are still pending.
 */
final class RunTrigger implements ShouldQueue, ShouldBeUnique
{
    use AppliesTrigger;
    use Queueable;

    public int $tries = 1;

    /**
     * Seconds the job may run, from `interlock.triggers.timeout`. It must stay below the queue connection's
     * `retry_after`, or the queue hands a slow job to a second worker while the first is still running it. The job
     * stops itself before then, and is failed rather than run again should it not.
     */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** Seconds after which the lock lapses, should a worker die without releasing it. */
    public int $uniqueFor = 3600;

    /**
     * @param  class-string<Model>  $model
     * @param  string  $trigger  the name of the trigger, which is the name of the transition it applies
     */
    public function __construct(
        public readonly string $model,
        public readonly string $trigger,
    ) {
        $this->queueAsConfigured();
    }

    public function uniqueId(): string
    {
        return $this->model . ':' . $this->trigger;
    }

    public function handle(GraphRepository $graphs, Repository $config, Cache $cache, Dispatcher $bus, ExceptionHandler $exceptions): void
    {
        $deadline = Deadline::within($this->timeout);
        $transition = $this->transition($graphs);

        if ($transition?->trigger === null) {
            return;
        }

        $pendingChunks = new PendingChunks($cache, $this->model, $this->trigger);

        if ($transition->triggerFansOut && $pendingChunks->any()) {
            return;
        }

        $maxPerRun = $config->integer('interlock.triggers.max_per_run', 1000);
        $chunkSize = $config->integer('interlock.triggers.chunk_size', 100);
        $cursor = $cache->get($this->cursorKey());

        $candidates = $this->candidates($transition->trigger)
            ->when($cursor !== null, fn(Builder $query) => $query->where($query->getModel()->getQualifiedKeyName(), '>', $cursor))
            ->limit($maxPerRun);

        $reachedTheEnd = $transition->triggerFansOut
            ? $this->fanOut($candidates, $chunkSize, $maxPerRun, $deadline, $bus, $pendingChunks, $cache)
            : $this->applyInChunks($candidates, $chunkSize, $maxPerRun, $deadline, $exceptions, $cache);

        if ($reachedTheEnd) {
            $cache->forget($this->cursorKey());
        }
    }

    /**
     * @param  Builder<Model>  $candidates
     * @return bool whether it got to every matching model, rather than stopping at the cap or the deadline
     */
    private function applyInChunks(Builder $candidates, int $chunkSize, int $maxPerRun, Deadline $deadline, ExceptionHandler $exceptions, Cache $cache): bool
    {
        $handled = 0;

        $finished = $candidates->chunkById($chunkSize, function (Collection $chunk) use ($deadline, $exceptions, $cache, &$handled) {
            $applied = $this->applyUntil($deadline, $chunk, $exceptions);
            $handled += $applied;

            if ($applied > 0) {
                $this->moveCursor($cache, $chunk->take($applied)->last()?->getKey());
            }

            return $applied === $chunk->count();
        });

        return $finished && $handled < $maxPerRun;
    }

    /**
     * Only the keys are loaded here; each chunk's job loads its models.
     *
     * @param  Builder<Model>  $candidates
     * @return bool whether it dispatched jobs for every matching model, rather than stopping at the cap or the deadline
     */
    private function fanOut(Builder $candidates, int $chunkSize, int $maxPerRun, Deadline $deadline, Dispatcher $bus, PendingChunks $pendingChunks, Cache $cache): bool
    {
        $key = $candidates->getModel()->getQualifiedKeyName();
        $keys = $candidates->orderBy($key)->pluck($key);
        $chunks = $keys->chunk($chunkSize);

        if ($chunks->isEmpty()) {
            return true;
        }

        // Long enough for every chunk job to run out its timeout in turn on one worker.
        $seconds = $this->timeout * $chunks->count();
        $run = $pendingChunks->start($chunks->count(), $seconds);

        foreach ($chunks as $index => $chunk) {
            if ($deadline->passed()) {
                return false;
            }

            $job = new RunTriggerChunk($this->model, $this->trigger, $chunk->values()->all(), $run, $index);
            $pendingChunks->dispatch($run, $index, $seconds, fn() => $bus->dispatch($job));
            $this->moveCursor($cache, $chunk->last());
        }

        return $keys->count() < $maxPerRun;
    }

    private function cursorKey(): string
    {
        return 'interlock:triggers:' . $this->uniqueId() . ':cursor';
    }

    /**
     * The cursor follows a run, chunk by chunk, so the next run carries on after the last model it got to: a backlog
     * drains even when its first models are skipped on every run, and so does one whose runs die before their end. A
     * run that reached the end of the matching models clears it, so the next run starts from the first again.
     */
    private function moveCursor(Cache $cache, mixed $lastKey): void
    {
        $cache->forever($this->cursorKey(), $lastKey);
    }
}
