<?php

namespace RobertBoes\Interlock\Exceptions;

use InvalidArgumentException;

final class InvalidConfigurationException extends InvalidArgumentException implements InterlockException
{
    public static function belowMinimum(string $key, int $value, int $minimum, string $meaning): self
    {
        return new self(sprintf(
            'The config value [%s] is %d, but it must be %d or more: %s. Change it in config/interlock.php.',
            $key,
            $value,
            $minimum,
            $meaning,
        ));
    }
}
