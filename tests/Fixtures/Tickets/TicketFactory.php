<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Tickets;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'subject' => 'A ticket',
        ];
    }
}
