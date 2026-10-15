<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\MissingTransitionInputException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Exceptions\TriggerOnlyTransitionException;
use RobertBoes\Interlock\Exceptions\UnauthorizedTransitionException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineAnsweringASignalTwiceFromOneState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderWithASignalOnTwoMachines;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PaymentMachineAnsweringAnOrderSignal;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithSignalledTransitionTakingInput;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TicketAnsweringASignalTwiceFromOneState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionWithInputAndSignal;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Returns\ReturnedOrder;
use RobertBoes\Interlock\Tests\Fixtures\SupportTickets\Ticket;
use RobertBoes\Interlock\Tests\Fixtures\SupportTickets\TicketStatus;
use RobertBoes\Interlock\Tests\Fixtures\TriggerOnlyPosts\TriggerOnlyPost;

function answeredTicket(): Ticket
{
    return Ticket::create(['last_message' => 'Thanks, that fixed it.'])->transition('answer', Employee::staff());
}

function closedTicket(): Ticket
{
    return Ticket::create()->transition('close');
}

it('applies the transition that answers the signal from the current state, and returns its name', function (Closure $ticket, string $applied) {
    $ticket = $ticket();

    expect($ticket->signal('reply'))->toBe($applied)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Open);
})->with([
    'an answered ticket' => [fn() => answeredTicket(), 'customer_replied'],
    'a closed ticket' => [fn() => closedTicket(), 'reopen'],
]);

it('answers a signal of its own name when a transition declares none', function () {
    $ticket = Ticket::create();

    expect($ticket->signal('answer', Employee::staff()))->toBe('answer')
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Answered);
});

it('applies a transition from a parent state by its signal', function () {
    $ticket = answeredTicket();

    expect($ticket->signal('close'))->toBe('close')
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Closed);
});

it('returns null and writes nothing when no transition answers the signal from the current state', function () {
    $ticket = Ticket::create();
    Event::fake([Transitioning::class, Transitioned::class]);

    expect($ticket->signal('reply'))->toBeNull()
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->history)->toBeEmpty();

    Event::assertNothingDispatched();
});

it('checks the stored state, not an unsaved assignment', function () {
    $ticket = closedTicket();
    $ticket->status = TicketStatus::Answered;

    expect($ticket->signal('reply'))->toBe('reopen')
        ->and($ticket->fresh()->reopen_count)->toBe(1);
});

it('throws when no transition answers the signal from any state', function () {
    expect(fn() => Ticket::create()->signal('rely'))->toThrow(
        UnknownTransitionException::class,
        Ticket::class . ' has no transition that answers signal [rely]. Its signals are [answer, reply, close].',
    );
});

it('does not answer a signal by the name of a transition that declares another', function () {
    expect(fn() => closedTicket()->signal('reopen'))->toThrow(UnknownTransitionException::class, 'has no transition that answers signal [reopen]');
});

it('runs the transition\'s apply step', function () {
    $ticket = closedTicket();

    $ticket->signal('reply');

    expect($ticket->fresh()->reopen_count)->toBe(1);
});

it('checks the transition\'s guards', function () {
    $ticket = Ticket::create()->transition('answer', Employee::staff());

    expect(thrownBy(fn() => $ticket->signal('reply')))
        ->toBeInstanceOf(FailedGuardException::class)
        ->guard->toBe('has_message');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Answered);
});

it('authorizes the actor given, null included', function () {
    expect(fn() => Ticket::create()->signal('answer', null))->toThrow(UnauthorizedTransitionException::class, 'You may not apply transition [answer].');
});

it('applies the transition on behalf of the authenticated user by default', function () {
    $this->actingAs(Employee::staff(id: 9));
    $ticket = Ticket::create();

    expect($ticket->signal('answer'))->toBe('answer')
        ->and($ticket->history()->sole()->only(['actor_type', 'actor_id']))->toBe(['actor_type' => Employee::class, 'actor_id' => 9]);
});

it('lets no one apply a trigger-only transition by its signal', function () {
    $post = TriggerOnlyPost::create()->transition('submit');

    expect(fn() => $post->signal('publish'))->toThrow(TriggerOnlyTransitionException::class, 'Only its trigger applies transition [publish].');
});

it('throws when the transition its own name answers takes input', function () {
    expect(fn() => ReturnedOrder::create()->signal('return_to_sender'))->toThrow(MissingTransitionInputException::class, 'Transition [return_to_sender] takes input');
});

it('throws stale state when another instance changed the state first, without trying again', function () {
    $ticket = answeredTicket();
    $stale = Ticket::find($ticket->getKey());

    $ticket->transition('close');

    expect(fn() => $stale->signal('reply'))->toThrow(StaleStateException::class);

    expect($ticket->fresh())
        ->status->toBe(TicketStatus::Closed)
        ->reopen_count->toBe(0);
});

it('records the transition\'s name in history and the dispatched events, not the signal', function () {
    $ticket = closedTicket();
    Event::fake([Transitioning::class, Transitioned::class]);

    $ticket->signal('reply');

    expect($ticket->history()->latest('id')->first()->transition_name)->toBe('reopen');
    Event::assertDispatched(Transitioning::class, fn(Transitioning $event) => $event->transition === 'reopen');
    Event::assertDispatched(Transitioned::class, fn(Transitioned $event) => $event->transition === 'reopen');
});

it('records the transition its signal applied while faking', function () {
    Interlock::fake();
    $ticket = closedTicket();

    $ticket->signal('reply');

    Interlock::assertTransitioned($ticket, 'reopen');
});

it('offers available transitions with the signal they answer', function () {
    expect(answeredTicket()->availableTransitions(null)->toArray())->toBe([
        ['name' => 'customer_replied', 'signal' => 'reply', 'state_attribute' => 'status', 'target_state' => 'open'],
        ['name' => 'close', 'signal' => 'close', 'state_attribute' => 'status', 'target_state' => 'closed'],
    ]);
});

it('rejects two transitions that answer one signal from the same state, a parent state expanded', function () {
    expect(fn() => TicketAnsweringASignalTwiceFromOneState::create())->toThrow(
        InvalidDefinitionException::class,
        'Signal [reply] is answered from state [answered] by both [nudge] and [customer_replied] in ' . MachineAnsweringASignalTwiceFromOneState::class . '. A signal applies one transition per state, so give one of them another signal or other from-states.',
    );
});

it('rejects a signal that transitions on two machines of a model answer', function () {
    expect(fn() => OrderWithASignalOnTwoMachines::create())->toThrow(
        InvalidDefinitionException::class,
        'Signal [ship] is answered by both [ship] in ' . OrderStatusMachine::class . ' for [status] and [capture_payment] in ' . PaymentMachineAnsweringAnOrderSignal::class . ' for [payment_status] on ' . OrderWithASignalOnTwoMachines::class . '. Signal names are unique per model, so rename one of them.',
    );
});

it('rejects a signal on a transition that takes input', function () {
    expect(fn() => PostWithSignalledTransitionTakingInput::create())->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionWithInputAndSignal::class . ' answers signal [go_live], but its constructor takes input. A signal applies the transition by name, without input: remove #[OnSignal], or give the constructor parameters defaults.',
    );
});
