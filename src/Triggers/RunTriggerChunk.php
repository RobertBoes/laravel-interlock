<?php

namespace RobertBoes\Interlock\Triggers;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\GraphRepository;
use Throwable;

/**
 * Applies a trigger's transition to one chunk of the models its run found, for a trigger that fans out. The models
 * may have changed while the job waited, so only those that still match are loaded; never retried, like the run. Those
 * it has no time left for are picked up by a later run, as they still match.
 */
final class RunTriggerChunk implements ShouldQueue
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

    /**
     * @param  class-string<Model>  $model
     * @param  string  $trigger  the name of the trigger, which is the name of the transition it applies
     * @param  array<int, mixed>  $keys  the keys of the models in the chunk
     * @param  string  $run  the ID of the run that dispatched the job
     * @param  int  $chunk  which of the run's chunks it is, from 0
     */
    public function __construct(
        public readonly string $model,
        public readonly string $trigger,
        public readonly array $keys,
        public readonly string $run,
        public readonly int $chunk,
    ) {
        $this->queueAsConfigured();
    }

    public function handle(GraphRepository $graphs, Cache $cache, ExceptionHandler $exceptions): void
    {
        $deadline = Deadline::within($this->timeout);

        try {
            $condition = $this->transition($graphs)?->trigger;

            if ($condition === null) {
                return;
            }

            $candidates = $this->candidates($condition)->whereKey($this->keys);

            $this->applyUntil($deadline, $candidates->orderBy($candidates->getModel()->getQualifiedKeyName())->get(), $exceptions);
        } finally {
            $this->finish($cache);
        }
    }

    /**
     * Called by the queue however the job ended, including when a worker was killed or the job timed out, which skips
     * the finally block above. Finishing twice is harmless.
     */
    public function failed(?Throwable $exception = null): void
    {
        $this->finish(Container::getInstance()->make(Cache::class));
    }

    private function finish(Cache $cache): void
    {
        (new PendingChunks($cache, $this->model, $this->trigger))->finish($this->run, $this->chunk);
    }
}
