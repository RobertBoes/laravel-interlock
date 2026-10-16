<?php

namespace RobertBoes\Interlock\Triggers;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Ends the job before the queue's timeout kills it, leaving the last quarter for the model in hand and saving progress.
 */
final readonly class Deadline
{
    private function __construct(private ?CarbonInterface $at) {}

    /**
     * A timeout of 0 means no timeout, as it does on the queue.
     */
    public static function within(int $timeout): self
    {
        return new self($timeout === 0 ? null : Date::now()->addMilliseconds($timeout * 750));
    }

    public function passed(): bool
    {
        return $this->at !== null && Date::now()->greaterThanOrEqualTo($this->at);
    }
}
