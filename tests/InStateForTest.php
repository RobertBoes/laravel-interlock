<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use RobertBoes\Interlock\Exceptions\InvalidStateQueryException;
use RobertBoes\Interlock\Scopes\InStateFor;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Tickets\Ticket;
use RobertBoes\Interlock\Tests\Fixtures\Tickets\TicketPriority;
use RobertBoes\Interlock\Tests\Fixtures\Tickets\TicketStatus;
use RobertBoes\Interlock\Triggers\RunTrigger;

beforeEach(fn() => Carbon::setTestNow('2026-03-10 09:00:00'));

function ticketEntered(TicketStatus $status, string $enteredAt, TicketPriority $priority = TicketPriority::Normal, ?string $priorityEnteredAt = null): Ticket
{
    return Ticket::factory()->create([
        'status' => $status,
        'status_changed_at' => $enteredAt,
        'priority' => $priority,
        'priority_changed_at' => $priorityEnteredAt ?? $enteredAt,
    ]);
}

/**
 * @return list<int>
 */
function ticketsMatching(InStateFor $scope): array
{
    return Ticket::query()->tap($scope)->orderBy('id')->pluck('id')->all();
}

it('finds the models that entered a state at least the duration ago', function () {
    $longResolved = ticketEntered(TicketStatus::Resolved, '2026-03-01 09:00:00');
    $resolvedExactly = ticketEntered(TicketStatus::Resolved, '2026-03-03 09:00:00');
    ticketEntered(TicketStatus::Resolved, '2026-03-03 09:00:01');
    ticketEntered(TicketStatus::Open, '2026-03-01 09:00:00');

    expect(ticketsMatching(new InStateFor(TicketStatus::Resolved, days: 7)))->toBe([$longResolved->getKey(), $resolvedExactly->getKey()]);
});

it('takes the duration in minutes, hours, days or as an interval', function (InStateFor $scope) {
    $matching = ticketEntered(TicketStatus::Resolved, '2026-03-10 07:00:00');
    ticketEntered(TicketStatus::Resolved, '2026-03-10 08:30:00');

    expect(ticketsMatching($scope))->toBe([$matching->getKey()]);
})->with([
    'minutes' => fn() => new InStateFor(TicketStatus::Resolved, minutes: 120),
    'hours' => fn() => new InStateFor(TicketStatus::Resolved, hours: 2),
    'days and hours' => fn() => new InStateFor(TicketStatus::Resolved, days: 0, hours: 1, minutes: 31),
    'an interval' => fn() => new InStateFor(TicketStatus::Resolved, CarbonInterval::hours(2)),
]);

it('finds the models in any of several states, expanding a parent state to its children', function () {
    $open = ticketEntered(TicketStatus::Open, '2026-03-01 09:00:00');
    $waiting = ticketEntered(TicketStatus::Waiting, '2026-03-01 09:00:00');
    $resolved = ticketEntered(TicketStatus::Resolved, '2026-03-01 09:00:00');
    ticketEntered(TicketStatus::Closed, '2026-03-01 09:00:00');

    expect(ticketsMatching(new InStateFor(TicketStatus::Active, days: 7)))->toBe([$open->getKey(), $waiting->getKey()])
        ->and(ticketsMatching(new InStateFor([TicketStatus::Waiting, TicketStatus::Resolved], days: 7)))->toBe([$waiting->getKey(), $resolved->getKey()]);
});

it('works out the cutoff when the query runs', function () {
    $ticket = ticketEntered(TicketStatus::Resolved, '2026-03-05 09:00:00');
    $scope = new InStateFor(TicketStatus::Resolved, days: 7);

    expect(ticketsMatching($scope))->toBe([]);

    Carbon::setTestNow('2026-03-12 09:00:00');

    expect(ticketsMatching($scope))->toBe([$ticket->getKey()]);
});

it('constrains only the time when no states are given', function () {
    $open = ticketEntered(TicketStatus::Open, '2026-03-01 09:00:00');
    $closed = ticketEntered(TicketStatus::Closed, '2026-03-02 09:00:00');
    ticketEntered(TicketStatus::Resolved, '2026-03-09 09:00:00');

    expect(ticketsMatching(new InStateFor(days: 7, stateAttribute: 'status')))->toBe([$open->getKey(), $closed->getKey()]);
});

it('queries the machine the state attribute names', function () {
    $urgentLong = ticketEntered(TicketStatus::Open, '2026-03-09 09:00:00', TicketPriority::Urgent, '2026-03-01 09:00:00');
    ticketEntered(TicketStatus::Open, '2026-03-01 09:00:00', TicketPriority::Urgent, '2026-03-09 09:00:00');

    expect(ticketsMatching(new InStateFor(TicketPriority::Urgent, days: 7)))->toBe([$urgentLong->getKey()])
        ->and(ticketsMatching(new InStateFor(TicketPriority::Urgent, days: 7, stateAttribute: 'priority')))->toBe([$urgentLong->getKey()])
        ->and(ticketsMatching(new InStateFor(days: 7, stateAttribute: 'priority')))->toBe([$urgentLong->getKey()]);
});

it('never matches a model whose column is null', function () {
    $ticket = ticketEntered(TicketStatus::Resolved, '2026-03-01 09:00:00');
    Ticket::query()->toBase()->update(['status_changed_at' => null]);

    expect(ticketsMatching(new InStateFor(TicketStatus::Resolved, days: 7)))->toBe([])
        ->and(ticketsMatching(new InStateFor(days: 7, stateAttribute: 'status')))->toBe([])
        ->and($ticket->fresh()->status_changed_at)->toBeNull();
});

it('applies a trigger to the models in its from-states for the duration', function () {
    $due = ticketEntered(TicketStatus::Resolved, '2026-03-03 09:00:00');
    $notYetDue = ticketEntered(TicketStatus::Resolved, '2026-03-04 09:00:00');
    $open = ticketEntered(TicketStatus::Open, '2026-03-01 09:00:00');

    app()->call([new RunTrigger(Ticket::class, 'close'), 'handle']);

    expect($due->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($notYetDue->fresh()->status)->toBe(TicketStatus::Resolved)
        ->and($open->fresh()->status)->toBe(TicketStatus::Open);
});

it('is a scope method with HasStateScopes', function () {
    $resolved = ticketEntered(TicketStatus::Resolved, '2026-03-01 09:00:00');
    ticketEntered(TicketStatus::Resolved, '2026-03-09 09:00:00');

    expect(Ticket::whereInStateFor(TicketStatus::Resolved, days: 7)->pluck('id')->all())->toBe([$resolved->getKey()])
        ->and(Ticket::query()->whereInStateFor(stateAttribute: 'status', hours: 25)->pluck('id')->all())->toBe([$resolved->getKey()]);
});

it('throws when the machine records no time it entered a state', function () {
    expect(fn() => Post::query()->tap(new InStateFor(PostStatus::Ready, days: 7))->get())->toThrow(
        InvalidStateQueryException::class,
        Post::class . ' does not record when its [status] entered a state. Declare the column with enteredAt: on its #[UseStateMachine] to query how long it has been in a state.',
    )
        ->and(fn() => Post::query()->tap(new InStateFor(days: 7))->get())->toThrow(InvalidStateQueryException::class, 'does not record when its [status] entered a state');
});

it('throws when no states are given and the model has several machines', function () {
    expect(fn() => Ticket::query()->tap(new InStateFor(days: 7))->get())->toThrow(
        InvalidStateQueryException::class,
        Ticket::class . ' has several state machines, on [status, priority]. Pass the state attribute to query as the stateAttribute argument.',
    );
});

it('throws for a state of another enum', function () {
    expect(fn() => Ticket::query()->tap(new InStateFor(OrderStatus::Pending, days: 7))->get())->toThrow(
        InvalidStateQueryException::class,
        Ticket::class . ' has no state machine with states of ' . OrderStatus::class . '. Its state attributes are [status, priority].',
    );
});

it('throws when no duration is given', function () {
    expect(fn() => new InStateFor(TicketStatus::Resolved))->toThrow(
        InvalidStateQueryException::class,
        'Pass how long the models have been in the state: minutes, hours, days or an interval.',
    );
});
