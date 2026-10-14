<?php

namespace RobertBoes\Interlock\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A mass write through the Eloquent builder, such as `Order::where(...)->update([...])`, that wrote state attributes or
 * guarded attributes because a bypass was active. Dispatched after the write. It names no models: the query decides
 * which rows it wrote.
 */
final readonly class BypassedMassWrite
{
    /**
     * @param  class-string<Model>  $model
     * @param  string  $method  the builder method that wrote, such as "update" or "insert"
     * @param  list<string>  $attributes  the state attributes and guarded attributes it wrote
     */
    public function __construct(
        public string $reason,
        public string $model,
        public string $method,
        public array $attributes,
    ) {}
}
