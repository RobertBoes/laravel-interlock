<?php

namespace RobertBoes\Interlock;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class InterlockBuilder extends Builder
{
    use RejectsDirectWrites;
}
