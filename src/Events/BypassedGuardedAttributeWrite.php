<?php

namespace RobertBoes\Interlock\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * The values are as the model casts them.
 */
final readonly class BypassedGuardedAttributeWrite
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
