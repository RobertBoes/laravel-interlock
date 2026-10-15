<?php

namespace RobertBoes\Interlock\Triggers;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class TriggerStatus
{
    /**
     * @param  class-string<Model>  $model
     * @param  int  $matching  the models that match the trigger now
     * @param  CarbonInterface|null  $matchingSince  since when models have matched at the start of every run
     */
    public function __construct(
        public string $model,
        public string $transition,
        public int $matching,
        public ?CarbonInterface $lastRunAt,
        public ?CarbonInterface $matchingSince,
        public bool $overdue,
    ) {}
}
