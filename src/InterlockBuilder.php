<?php

namespace RobertBoes\Interlock;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent builder of an interlocked model without a custom builder of its own.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class InterlockBuilder extends Builder
{
    use RejectsDirectWrites;
}
