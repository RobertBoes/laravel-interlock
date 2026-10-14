<?php

namespace RobertBoes\Interlock\Support;

use BackedEnum;

/**
 * @internal
 */
final class StateValues
{
    /**
     * The states' values as a comma-separated list, as messages show them.
     *
     * @param  list<BackedEnum>  $states
     */
    public static function listed(array $states): string
    {
        return implode(', ', array_map(fn(BackedEnum $state) => $state->value, $states));
    }
}
