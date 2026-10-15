<?php

namespace RobertBoes\Interlock\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class BypassedMassWrite
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
