<?php

namespace RobertBoes\Interlock\Triggers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;

/**
 * Counts the chunk jobs of a fanned-out trigger run that have not finished yet. The run's own unique lock is released
 * as soon as it has dispatched them, so while any are left, the next run must not dispatch the same models again.
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
        return $this->cache->get($this->key(), 0) > 0;
    }

    /**
     * @param  int  $seconds  after which the count lapses, should a worker die before its chunk finishes
     */
    public function expect(int $chunks, int $seconds): void
    {
        $this->cache->put($this->key(), $chunks, $seconds);
    }

    public function finishOne(): void
    {
        $left = $this->cache->decrement($this->key());

        if ($left === false || $left <= 0) {
            $this->cache->forget($this->key());
        }
    }

    private function key(): string
    {
        return 'interlock:triggers:' . $this->model . ':' . $this->trigger . ':pending-chunks';
    }
}
