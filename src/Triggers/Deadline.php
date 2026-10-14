<?php

namespace RobertBoes\Interlock\Triggers;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * When a trigger job stops taking on models, so that it ends by itself before the queue's timeout kills it. Three
 * quarters of the timeout leaves the last quarter for the model in hand and for saving where the job got to.
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
