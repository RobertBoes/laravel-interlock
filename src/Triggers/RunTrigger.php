<?php

namespace RobertBoes\Interlock\Triggers;

use Closure;
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
 * Unique per trigger, so a slow run and the next never overlap. Never retried: what fails still matches on a later run.
 */
final class RunTrigger implements ShouldQueue, ShouldBeUnique
{
    use AppliesTrigger;
    use Queueable;

    private const int MIN_PENDING_CHUNK_SECONDS = 300;

    public int $tries = 1;

    /**
     * Must stay below the queue connection's `retry_after`, or a slow job is handed to a second worker while it runs.
     */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    /**
     * @param  class-string<Model>  $model
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

        $runs = new TriggerRuns($cache, $this->model, $this->trigger);
        $runs->start($this->candidates($transition->trigger)->exists());

        $this->run($transition->trigger, $transition->triggerFansOut, $deadline, $config, $cache, $bus, $exceptions);

        $runs->finish();

        if (! $transition->triggerFansOut && ! $this->candidates($transition->trigger)->exists()) {
            $runs->clear();
        }
    }

    /**
     * A fanned-out trigger whose chunk jobs are still pending counts as having run: the scheduler and queue are working.
     *
     * @param  Closure(Builder<Model>): mixed  $condition
     */
    private function run(Closure $condition, bool $fansOut, Deadline $deadline, Repository $config, Cache $cache, Dispatcher $bus, ExceptionHandler $exceptions): void
    {
        $pendingChunks = new PendingChunks($cache, $this->model, $this->trigger);

        if ($fansOut && $pendingChunks->any()) {
            return;
        }

        $maxPerRun = $config->integer('interlock.triggers.max_per_run', 1000);
        $chunkSize = $config->integer('interlock.triggers.chunk_size', 100);
        $cursor = $cache->get($this->cursorKey());

        $candidates = $this->candidates($condition)
            ->when($cursor !== null, fn(Builder $query) => $query->where($query->getModel()->getQualifiedKeyName(), '>', $cursor))
            ->limit($maxPerRun);

        $reachedTheEnd = $fansOut
            ? $this->fanOut($candidates, $chunkSize, $maxPerRun, $deadline, $bus, $pendingChunks, $cache)
            : $this->applyInChunks($candidates, $chunkSize, $maxPerRun, $deadline, $exceptions, $cache);

        if ($reachedTheEnd) {
            $cache->forget($this->cursorKey());
        }
    }

    /**
     * @param  Builder<Model>  $candidates
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
     * @param  Builder<Model>  $candidates
     */
    private function fanOut(Builder $candidates, int $chunkSize, int $maxPerRun, Deadline $deadline, Dispatcher $bus, PendingChunks $pendingChunks, Cache $cache): bool
    {
        $key = $candidates->getModel()->getQualifiedKeyName();
        $keys = $candidates->orderBy($key)->pluck($key);
        $chunks = $keys->chunk($chunkSize);

        if ($chunks->isEmpty()) {
            return true;
        }

        // Long enough for every chunk job to time out in turn on one worker, with a floor so a few chunk jobs still
        // outlast a backed-up queue.
        $seconds = max($this->timeout * $chunks->count(), self::MIN_PENDING_CHUNK_SECONDS);
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
     * So a backlog drains even when its first models are skipped on every run, or its runs die before their end.
     */
    private function moveCursor(Cache $cache, mixed $lastKey): void
    {
        $cache->forever($this->cursorKey(), $lastKey);
    }
}
