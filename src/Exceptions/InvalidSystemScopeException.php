<?php

namespace RobertBoes\Interlock\Exceptions;

use InvalidArgumentException;

final class InvalidSystemScopeException extends InvalidArgumentException implements InterlockException
{
    public static function withoutReason(): self
    {
        return new self('System code needs a reason, such as "Deletion job", so history says why a transition was applied without authorization.');
    }
}
