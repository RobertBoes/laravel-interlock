<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use Illuminate\Database\Eloquent\Model;

/**
 * The side record Ship writes in its apply step.
 *
 * @property string $tracking_code
 */
class Shipment extends Model
{
    protected $guarded = [];
}
