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
 * of them per run. Unique per trigger, so a slow run and the next one never process the same trigger at once, and
 * never retried: what fails still matches on a later run. A trigger that fans out dispatches one job per chunk instead,
 * and does nothing while the chunk jobs of its last run are still pending.
 */
final class RunTrigger implements ShouldQueue, ShouldBeUnique
{
    use AppliesTrigger;
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
    ) {
        $this->queueAsConfigured();
    }

    public function uniqueId(): string
    {
        return $this->model . ':' . $this->trigger;
    }

    public function handle(GraphRepository $graphs, Repository $config, Cache $cache, Dispatcher $bus, ExceptionHandler $exceptions): void
    {
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

        $handled = $transition->triggerFansOut
            ? $this->fanOut($candidates, $chunkSize, $bus, $pendingChunks, $cache)
            : $this->applyInChunks($candidates, $chunkSize, $exceptions, $cache);

        if ($handled < $maxPerRun) {
            $cache->forget($this->cursorKey());
        }
    }

    /**
     * @param  Builder<Model>  $candidates
     * @return int how many models it got to
     */
    private function applyInChunks(Builder $candidates, int $chunkSize, ExceptionHandler $exceptions, Cache $cache): int
    {
        $handled = 0;

        $candidates->chunkById($chunkSize, function (Collection $chunk) use ($exceptions, $cache, &$handled) {
            foreach ($chunk as $candidate) {
                $this->apply($candidate, $exceptions);
            }

            $handled += $chunk->count();
            $this->moveCursor($cache, $chunk->last()?->getKey());
        });

        return $handled;
    }

    /**
     * Only the keys are loaded here; each chunk's job loads its models.
     *
     * @param  Builder<Model>  $candidates
     * @return int how many models it dispatched jobs for
     */
    private function fanOut(Builder $candidates, int $chunkSize, Dispatcher $bus, PendingChunks $pendingChunks, Cache $cache): int
    {
        $key = $candidates->getModel()->getQualifiedKeyName();
        $keys = $candidates->orderBy($key)->pluck($key);
        $chunks = $keys->chunk($chunkSize);

        // Counted before the first is dispatched, as a chunk job may run, and finish, before the next is dispatched.
        if ($chunks->isNotEmpty()) {
            $pendingChunks->expect($chunks->count(), $this->uniqueFor);
        }

        foreach ($chunks as $chunk) {
            $bus->dispatch(new RunTriggerChunk($this->model, $this->trigger, $chunk->values()->all()));
            $this->moveCursor($cache, $chunk->last());
        }

        return $keys->count();
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
