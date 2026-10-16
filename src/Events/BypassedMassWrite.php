<?php

namespace RobertBoes\Interlock\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Dispatched once the write commits, and not at all when it rolls back.
 */
final readonly class BypassedMassWrite implements ShouldDispatchAfterCommit
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $attributes  the state attributes, guarded attributes and entered-at columns it wrote
     */
    public function __construct(
        public string $reason,
        public string $model,
        public string $method,
        public array $attributes,
    ) {}
}
