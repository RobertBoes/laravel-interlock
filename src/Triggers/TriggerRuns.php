<?php

namespace RobertBoes\Interlock\Triggers;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Kept as timestamps: a cache store may refuse to unserialize date objects.
 */
final readonly class TriggerRuns
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private Cache $cache,
        private string $model,
        private string $trigger,
    ) {}

    public function start(bool $matching): void
    {
        if (! $matching) {
            $this->cache->forget($this->key('matching_since'));

            return;
        }

        $this->cache->add($this->key('matching_since'), Date::now()->getTimestamp());
    }

    public function finish(): void
    {
        $this->cache->forever($this->key('last_run_at'), Date::now()->getTimestamp());
    }

    /**
     * Called once a run leaves nothing matching, so models that keep arriving between runs never make a healthy trigger overdue.
     */
    public function clear(): void
    {
        $this->cache->forget($this->key('matching_since'));
    }

    public function lastRunAt(): ?CarbonInterface
    {
        return $this->date('last_run_at');
    }

    public function matchingSince(): ?CarbonInterface
    {
        return $this->date('matching_since');
    }

    /**
     * Redis stores numbers unserialized and returns them as strings.
     */
    private function date(string $name): ?CarbonInterface
    {
        $timestamp = $this->cache->get($this->key($name));

        return is_numeric($timestamp) ? Date::createFromTimestamp((int) $timestamp, date_default_timezone_get()) : null;
    }

    private function key(string $name): string
    {
        return 'interlock:triggers:' . $this->model . ':' . $this->trigger . ':' . $name;
    }
}
