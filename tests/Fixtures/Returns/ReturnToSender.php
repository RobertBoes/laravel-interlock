<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Returns;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\Guard;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Shipment;
use RobertBoes\Interlock\Transition;

/**
 * A transition whose input is a model, which the container could build empty.
 */
#[FromStates(OrderStatus::Pending)]
#[TargetState(OrderStatus::Cancelled)]
final class ReturnToSender extends Transition
{
    public function __construct(public readonly Shipment $shipment) {}

    #[Guard('Only a stored shipment can be returned.')]
    public function shipmentIsStored(): bool
    {
        return $this->shipment->exists;
    }
}
