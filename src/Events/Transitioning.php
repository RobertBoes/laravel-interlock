<?php

namespace RobertBoes\Interlock\Events;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched inside the transition's transaction, after its apply step and before the write.
 */
final readonly class Transitioning
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public string $transition,
        public BackedEnum $fromState,
        public BackedEnum $targetState,
    ) {}
}
