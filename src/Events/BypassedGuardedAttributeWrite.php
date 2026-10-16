<?php

namespace RobertBoes\Interlock\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched once the write commits, and not at all when it rolls back. The values are as the model casts them.
 */
final readonly class BypassedGuardedAttributeWrite implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    public function __construct(
        public string $reason,
        public Model $model,
        public string $attribute,
        public mixed $oldValue,
        public mixed $newValue,
    ) {}
}
