<?php

namespace RobertBoes\Interlock\System;

use Closure;
use RobertBoes\Interlock\Exceptions\InvalidSystemScopeException;

/**
 * Bound as a singleton, so a fresh app instance (the next test, another Octane worker) starts outside the scope.
 */
final class SystemScope
{
    /** @var list<string> */
    private array $reasons = [];

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $reason, Closure $callback): mixed
    {
        if (trim($reason) === '') {
            throw InvalidSystemScopeException::withoutReason();
        }

        $this->reasons[] = $reason;

        try {
            return $callback();
        } finally {
            array_pop($this->reasons);
        }
    }

    public function reason(): ?string
    {
        return $this->reasons === [] ? null : $this->reasons[array_key_last($this->reasons)];
    }
}
