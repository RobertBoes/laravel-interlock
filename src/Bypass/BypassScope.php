<?php

namespace RobertBoes\Interlock\Bypass;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use RobertBoes\Interlock\Exceptions\InvalidBypassException;

/**
 * Bound as a singleton, so a fresh app instance (the next test, another Octane worker) starts without bypasses.
 */
final class BypassScope
{
    public const string FACTORY_REASON = 'factory';

    /** @var list<string> */
    private array $reasons = [];

    private ?string $appWideReason = null;

    public function __construct(private readonly bool $factoriesBypass = true) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $reason, Closure $callback): mixed
    {
        $this->reasons[] = $this->requireReason($reason);

        try {
            return $callback();
        } finally {
            array_pop($this->reasons);
        }
    }

    public function bypassUntilAppEnds(string $reason): void
    {
        $this->appWideReason = $this->requireReason($reason);
    }

    /**
     * Call it only for a (possible) direct write: it may walk the call stack.
     */
    public function reason(): ?string
    {
        if ($this->reasons !== []) {
            return $this->reasons[array_key_last($this->reasons)];
        }

        return $this->appWideReason ?? ($this->factoriesBypass && $this->calledFromAFactory() ? self::FACTORY_REASON : null);
    }

    private function requireReason(string $reason): string
    {
        if (trim($reason) === '') {
            throw InvalidBypassException::withoutReason();
        }

        return $reason;
    }

    /**
     * Factories have no global hook, so they are detected from the call stack.
     */
    private function calledFromAFactory(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class']) && is_a($frame['class'], Factory::class, true)) {
                return true;
            }
        }

        return false;
    }
}
