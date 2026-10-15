<?php

namespace RobertBoes\Interlock\Triggers;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Ends the job before the queue's timeout kills it, leaving the last quarter for the model in hand and saving progress.
 */
final readonly class Deadline
{
    private function __construct(private CarbonInterface $at) {}

    public static function within(int $timeout): self
    {
        return new self(Date::now()->addSeconds(intdiv($timeout * 3, 4)));
    }

    public function passed(): bool
    {
        return Date::now()->greaterThanOrEqualTo($this->at);
    }
}
