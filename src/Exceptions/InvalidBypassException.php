<?php

namespace RobertBoes\Interlock\Exceptions;

use InvalidArgumentException;

final class InvalidBypassException extends InvalidArgumentException implements InterlockException
{
    public static function withoutReason(): self
    {
        return new self('A bypass needs a reason, such as "Import posts from the old blog", so the audit trail says why the state was written directly.');
    }

    public static function outsideTests(): self
    {
        return new self('Interlock::bypassForTest() only works while running tests. Wrap the writes in Interlock::bypass($reason, $callback) instead.');
    }
}
