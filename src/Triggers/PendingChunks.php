<?php

namespace RobertBoes\Interlock\Triggers;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * The run's unique lock is released once it has dispatched its chunk jobs, so this stops the next run from dispatching
 * the same models while any are pending.
 *
 * One marker per chunk job rather than a shared count, so a job that finishes twice (handle() and failed()) or late
 * cannot miscount the next run.
 */
final readonly class PendingChunks
{
    /**
     * @param  class-string<Model>  $model
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

    public function start(int $chunks, int $seconds): string
    {
        $run = Str::random(20);

        $this->cache->put($this->runKey(), ['id' => $run, 'chunks' => $chunks], $seconds);

        return $run;
    }

    /**
     * @param  Closure(): mixed  $dispatch
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
