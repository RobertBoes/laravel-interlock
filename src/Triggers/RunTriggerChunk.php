<?php

namespace RobertBoes\Interlock\Triggers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\GraphRepository;

/**
 * Applies a trigger's transition to one chunk of the models its run found, for a trigger that fans out. The models
 * may have changed while the job waited, so only those that still match are loaded; never retried, like the run.
 */
final class RunTriggerChunk implements ShouldQueue
{
    use AppliesTrigger;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  class-string<Model>  $model
     * @param  string  $trigger  the name of the trigger, which is the name of the transition it applies
     * @param  array<int, mixed>  $keys  the keys of the models in the chunk
     */
    public function __construct(
        public readonly string $model,
        public readonly string $trigger,
        public readonly array $keys,
    ) {
        $this->queueAsConfigured();
    }

    public function handle(GraphRepository $graphs, Cache $cache, ExceptionHandler $exceptions): void
    {
        try {
            $condition = $this->transition($graphs)?->trigger;

            if ($condition === null) {
                return;
            }

            $candidates = $this->candidates($condition)->whereKey($this->keys);

            foreach ($candidates->orderBy($candidates->getModel()->getQualifiedKeyName())->get() as $candidate) {
                $this->apply($candidate, $exceptions);
            }
        } finally {
            (new PendingChunks($cache, $this->model, $this->trigger))->finishOne();
        }
    }
}
