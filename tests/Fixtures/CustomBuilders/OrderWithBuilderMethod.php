<?php

namespace RobertBoes\Interlock\Tests\Fixtures\CustomBuilders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;

/**
 * An order that sets its custom builder by overriding newEloquentBuilder().
 */
#[UseStateMachine('status', OrderStatusMachine::class)]
class OrderWithBuilderMethod extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    /**
     * @param  QueryBuilder  $query
     * @return OrderBuilder<*>
     */
    public function newEloquentBuilder($query): OrderBuilder
    {
        return new OrderBuilder($query);
    }

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }
}
