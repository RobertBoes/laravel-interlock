<?php

namespace RobertBoes\Interlock\Events;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * A direct write to a state attribute that was saved because a bypass was active. Dispatched after the write.
 */
final readonly class BypassedWrite
{
    use SerializesModels;

    public function __construct(
        public string $reason,
        public Model $model,
        public string $stateAttribute,
        public ?BackedEnum $oldState,
        public ?BackedEnum $newState,
    ) {}
}
