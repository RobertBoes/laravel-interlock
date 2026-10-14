<?php

namespace RobertBoes\Interlock\Tests\Fixtures\CustomBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\RejectsDirectWrites;

/**
 * An app's own builder for its orders, with Interlock's trait added.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class OrderBuilder extends Builder
{
    use RejectsDirectWrites;

    /**
     * @return $this
     */
    public function retried(): static
    {
        return $this->where('delivery_attempts', '>', 0);
    }
}
