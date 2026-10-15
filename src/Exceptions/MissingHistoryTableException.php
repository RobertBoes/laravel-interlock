<?php

namespace RobertBoes\Interlock\Exceptions;

use RobertBoes\Interlock\StateMachine;
use RuntimeException;

final class MissingHistoryTableException extends RuntimeException implements InterlockException
{
    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function on(?string $connection, string $table, string $machine): self
    {
        return new self(sprintf(
            '%s records history, but the %s table does not exist on database connection [%s]. Publish and run the history migration with `php artisan vendor:publish --tag=interlock-migrations` and `php artisan migrate`, or remove #[RecordsHistory] from %s.',
            $machine,
            $table,
            $connection,
            $machine,
        ));
    }
}
