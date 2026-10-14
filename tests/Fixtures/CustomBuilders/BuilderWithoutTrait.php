<?php

namespace RobertBoes\Interlock\Tests\Fixtures\CustomBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An app's own builder for its orders, without Interlock's trait.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class BuilderWithoutTrait extends Builder {}
