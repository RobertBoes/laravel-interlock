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
 * The models may have changed while the job waited, so only those that still match are loaded. Never retried: those
 * left over still match on a later run.
 */
final class RunTriggerChunk implements ShouldQueue
{
    use AppliesTrigger;
    use Queueable;

    public int $tries = 1;

    /**
     * Must stay below the queue connection's `retry_after`, or a slow job is handed to a second worker while it runs.
     */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, mixed>  $keys
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
     * Also runs when a killed worker or a timeout skipped the finally block above. Finishing twice is harmless.
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
