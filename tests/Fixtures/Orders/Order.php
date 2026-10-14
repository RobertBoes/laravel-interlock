<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

/**
 * @property ?string $tracking_code
 * @property ?Carbon $delivered_at
 */
#[UseStateMachine('status', OrderStatusMachine::class)]
#[UseStateMachine('payment_status', PaymentStatusMachine::class)]
class Order extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'delivered_at' => 'datetime',
        ];
    }
}
