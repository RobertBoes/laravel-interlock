<?php

namespace RobertBoes\Interlock\Tests\Fixtures\CustomBuilders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;

/**
 * An order whose newEloquentBuilder() returns a custom builder that lacks Interlock's trait.
 */
#[UseStateMachine('status', OrderStatusMachine::class)]
class OrderWithBuilderMethodWithoutTrait extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    /**
     * @param  QueryBuilder  $query
     * @return BuilderWithoutTrait<*>
     */
    public function newEloquentBuilder($query): BuilderWithoutTrait
    {
        return new BuilderWithoutTrait($query);
    }

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }
}
