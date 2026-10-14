<?php

namespace RobertBoes\Interlock\Exceptions;

use LogicException;

final class InvalidFakeException extends LogicException implements InterlockException
{
    public static function outsideTests(): self
    {
        return new self('Interlock::fake() only works while running tests, because it skips the apply and after-commit steps of every transition.');
    }
}
