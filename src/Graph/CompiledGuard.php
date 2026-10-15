<?php

namespace RobertBoes\Interlock\Graph;

use Closure;

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
