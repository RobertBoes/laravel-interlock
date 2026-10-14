<?php

namespace RobertBoes\Interlock\Graph;

use Closure;

/**
 * One guard of a transition: a named check on the model, and the message a failure carries.
 */
final readonly class CompiledGuard
{
    /**
     * @param  Closure(\Illuminate\Database\Eloquent\Model): bool  $check
     */
    public function __construct(
        public string $name,
        public Closure $check,
        public string $message,
    ) {}
}
