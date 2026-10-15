<?php

namespace RobertBoes\Interlock\System;

use Closure;
use RobertBoes\Interlock\Interlock;

/**
 * Job middleware that runs the job's handle() inside Interlock::asSystem(). It does not wrap the job's failed() method.
 */
final readonly class RunsAsSystem
{
    public function __construct(public string $reason) {}

    public function handle(object $job, Closure $next): mixed
    {
        return Interlock::asSystem($this->reason, fn() => $next($job));
    }
}
