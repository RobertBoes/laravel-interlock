<?php

namespace RobertBoes\Interlock\Events;

use BackedEnum;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched once the write commits, and not at all when it rolls back.
 */
final readonly class BypassedWrite implements ShouldDispatchAfterCommit
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
