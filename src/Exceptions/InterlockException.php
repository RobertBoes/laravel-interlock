<?php

namespace RobertBoes\Interlock\Exceptions;

use Throwable;

/**
 * An interface rather than a base class, because the authorization exception must extend Laravel's own.
 */
interface InterlockException extends Throwable {}
