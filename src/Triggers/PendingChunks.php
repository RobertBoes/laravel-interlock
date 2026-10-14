<?php

namespace RobertBoes\Interlock\Triggers;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tracks the chunk jobs of a fanned-out trigger's last run that have not finished yet. The run's own unique lock is
 * released as soon as it has dispatched them, so while any are left, the next run must not dispatch the same models
 * again.
 *
 * Each chunk job has its own marker, scoped to its run, rather than a shared count: finishing removes the marker, so a
 * job that finishes twice (once in handle(), once in failed()) or that finishes late, after its run lapsed, cannot
 * miscount the run that comes after it. A marker is set just before its job is dispatched and removed should the
 * dispatch throw, so only jobs that were dispatched are waited for.
 */
final readonly class PendingChunks
{
    /**
     * @param  class-string<Model>  $model
     * @param  string  $trigger  the name of the trigger
     */
    public function __construct(
        private Cache $cache,
        private string $model,
        private string $trigger,
    ) {}

    public function any(): bool
    {
        $run = $this->cache->get($this->runKey());

        if (! is_array($run)) {
            return false;
        }

        $chunks = range(0, (int) $run['chunks'] - 1);
        $markers = $this->cache->many(array_map(fn(int $chunk) => $this->chunkKey((string) $run['id'], $chunk), $chunks));

        return array_filter($markers) !== [];
    }

    /**
     * @param  int  $seconds  after which the run lapses, should its chunk jobs never finish
     * @return string the run's ID
     */
    public function start(int $chunks, int $seconds): string
    {
        $run = Str::random(20);

        $this->cache->put($this->runKey(), ['id' => $run, 'chunks' => $chunks], $seconds);

        return $run;
    }

    /**
     * @param  Closure(): mixed  $dispatch
     * @param  int  $seconds  after which the chunk counts as finished, should its job never finish
     */
    public function dispatch(string $run, int $chunk, int $seconds, Closure $dispatch): void
    {
        // Set first, as the job may run, and finish, before the dispatch returns.
        $this->cache->put($this->chunkKey($run, $chunk), true, $seconds);

        try {
            $dispatch();
        } catch (Throwable $exception) {
            $this->finish($run, $chunk);

            throw $exception;
        }
    }

    public function finish(string $run, int $chunk): void
    {
        $this->cache->forget($this->chunkKey($run, $chunk));
    }

    private function runKey(): string
    {
        return 'interlock:triggers:' . $this->model . ':' . $this->trigger . ':run';
    }

    private function chunkKey(string $run, int $chunk): string
    {
        return $this->runKey() . ':' . $run . ':chunk:' . $chunk;
    }
}
