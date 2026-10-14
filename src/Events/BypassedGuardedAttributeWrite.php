<?php

namespace RobertBoes\Interlock\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A direct write to a guarded attribute that was saved because a bypass was active, such as a backfilled tracking
 * code. Dispatched after the write. The values are as the model casts them.
 */
final readonly class BypassedGuardedAttributeWrite
{
    public function __construct(
        public string $reason,
        public Model $model,
        public string $attribute,
        public mixed $oldValue,
        public mixed $newValue,
    ) {}
}
