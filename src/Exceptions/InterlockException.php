<?php

namespace RobertBoes\Interlock\Exceptions;

use Throwable;

/**
 * Implemented by every Interlock exception, so they can be caught or reported as a group.
 *
 * An interface rather than a base class, because the authorisation exception must extend Laravel's own.
 */
interface InterlockException extends Throwable {}
