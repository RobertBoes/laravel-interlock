<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\BypassedMassWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\History\HistoryEntry;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\Tickets\Ticket;
use RobertBoes\Interlock\Tests\Fixtures\Tickets\TicketPriority;
use RobertBoes\Interlock\Tests\Fixtures\Tickets\TicketStatus;
use RobertBoes\Interlock\Triggers\RunTrigger;

beforeEach(fn() => Carbon::setTestNow('2026-03-01 09:00:00'));

function storedEnteredAt(Ticket $ticket, string $column = 'status_changed_at'): ?string
{
    return Ticket::query()->toBase()->where('id', $ticket->getKey())->value($column);
}

it('changes nothing for a machine that declares no column', function () {
    $post = Post::create()->transition('submit');

    expect(array_keys($post->getChanges()))->toBe(['status'])
        ->and($post->getCasts())->not->toHaveKey('status_changed_at');
});

it('sets the column to now when the model is created', function () {
    $ticket = Ticket::create();

    expect(storedEnteredAt($ticket))->toBe('2026-03-01 09:00:00')
        ->and(storedEnteredAt($ticket, 'priority_changed_at'))->toBe('2026-03-01 09:00:00')
        ->and($ticket->status_changed_at?->toDateTimeString())->toBe('2026-03-01 09:00:00');
});

it('sets the column in the same write as the state on every transition', function () {
    $ticket = Ticket::create();
    Carbon::setTestNow('2026-03-02 10:00:00');

    $ticket->transition('await_reply');

    expect(storedEnteredAt($ticket))->toBe('2026-03-02 10:00:00')
        ->and(storedEnteredAt($ticket, 'priority_changed_at'))->toBe('2026-03-01 09:00:00')
        ->and($ticket->isDirty())->toBeFalse();
});

it('follows the leaf state, so moving between children of one parent resets it', function () {
    $ticket = Ticket::create()->transition('await_reply');
    Carbon::setTestNow('2026-03-03 10:00:00');

    $ticket->transition('receive_reply');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(storedEnteredAt($ticket))->toBe('2026-03-03 10:00:00');
});

it('resets it on a self-transition', function () {
    $ticket = Ticket::create()->transition('await_reply');
    Carbon::setTestNow('2026-03-04 10:00:00');

    $ticket->transition('remind');

    expect(storedEnteredAt($ticket))->toBe('2026-03-04 10:00:00');
});

it('sets the column of each machine a staged save transitions, and only those', function () {
    $ticket = Ticket::create();
    Carbon::setTestNow('2026-03-02 10:00:00');
    $ticket->stageTransition('await_reply')->stageTransition('escalate')->save();

    Carbon::setTestNow('2026-03-03 10:00:00');
    $ticket->transition('deescalate');

    expect(storedEnteredAt($ticket))->toBe('2026-03-02 10:00:00')
        ->and(storedEnteredAt($ticket, 'priority_changed_at'))->toBe('2026-03-03 10:00:00');
});

it('sets it when transitions are faked', function () {
    Interlock::fake();
    $ticket = Ticket::create();
    Carbon::setTestNow('2026-03-02 10:00:00');

    $ticket->transition('resolve');

    expect(storedEnteredAt($ticket))->toBe('2026-03-02 10:00:00');
});

it('sets it when a trigger applies the transition', function () {
    Carbon::setTestNow('2026-03-01 09:00:00');
    $ticket = Ticket::create()->transition('resolve');
    Carbon::setTestNow('2026-03-09 09:00:00');

    app()->call([new RunTrigger(Ticket::class, 'close'), 'handle']);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and(storedEnteredAt($ticket))->toBe('2026-03-09 09:00:00');
});

it('leaves it out of history', function () {
    $ticket = Ticket::create()->transition('resolve');
    Interlock::bypass('Fix a ticket', fn() => $ticket->update(['status' => TicketStatus::Waiting]));

    expect(HistoryEntry::query()->oldest('id')->get()->map(fn(HistoryEntry $entry) => [$entry->old_attributes, $entry->new_attributes])->all())->toBe([
        [['status' => 'open'], ['status' => 'resolved']],
        [['status' => 'resolved'], ['status' => 'waiting']],
    ]);
});

it('casts the column to datetime unless the model casts it already', function () {
    $ticket = Ticket::create()->fresh();

    expect($ticket->getCasts())->toMatchArray(['status_changed_at' => 'datetime', 'priority_changed_at' => 'immutable_datetime'])
        ->and($ticket->status_changed_at)->toBeInstanceOf(Carbon::class)
        ->and($ticket->priority_changed_at)->toBeInstanceOf(CarbonImmutable::class);
});

describe('bypassed writes', function () {
    it('sets the column when a bypassed write changes the state', function () {
        $ticket = Ticket::create();
        Carbon::setTestNow('2026-03-02 10:00:00');

        Interlock::bypass('Fix a ticket', fn() => $ticket->update(['status' => TicketStatus::Resolved]));

        expect(storedEnteredAt($ticket))->toBe('2026-03-02 10:00:00')
            ->and(storedEnteredAt($ticket, 'priority_changed_at'))->toBe('2026-03-01 09:00:00');
    });

    it('keeps the value a bypassed write sets the column to', function () {
        $ticket = Ticket::create();

        Interlock::bypass('Import a ticket', fn() => $ticket->update(['status' => TicketStatus::Resolved, 'status_changed_at' => '2025-12-24 12:00:00']));

        expect(storedEnteredAt($ticket))->toBe('2025-12-24 12:00:00');
    });

    it('leaves the column alone when a bypassed write leaves the state alone', function () {
        $ticket = Ticket::create();
        Carbon::setTestNow('2026-03-02 10:00:00');

        Interlock::bypass('Fix a ticket', fn() => $ticket->update(['subject' => 'Another subject']));

        expect(storedEnteredAt($ticket))->toBe('2026-03-01 09:00:00');
    });

    it('keeps the value a bypassed create or a factory sets the column to', function () {
        $imported = Interlock::bypass('Import a ticket', fn() => Ticket::create(['status' => TicketStatus::Resolved, 'status_changed_at' => '2025-12-24 12:00:00']));
        $made = Ticket::factory()->create(['status' => TicketStatus::Resolved, 'status_changed_at' => '2025-12-25 12:00:00']);
        $madeWithout = Ticket::factory()->create(['status' => TicketStatus::Resolved]);

        expect(storedEnteredAt($imported))->toBe('2025-12-24 12:00:00')
            ->and(storedEnteredAt($made))->toBe('2025-12-25 12:00:00')
            ->and(storedEnteredAt($madeWithout))->toBe('2026-03-01 09:00:00');
    });
});

describe('direct writes', function () {
    it('rejects writing the column outside a bypass, and writes nothing', function () {
        $ticket = Ticket::create();

        expect(fn() => $ticket->update(['status_changed_at' => '2025-12-24 12:00:00']))->toThrow(
            DirectWriteException::class,
            'Cannot save ' . Ticket::class . ' [' . $ticket->getKey() . ']: its [status_changed_at] records when its [status] entered its state, and was changed outside a transition. Only Interlock may write it, unless a bypass is active.',
        );

        expect(storedEnteredAt($ticket))->toBe('2026-03-01 09:00:00');
    });

    it('rejects writing the column alongside a transition of another machine', function () {
        $ticket = Ticket::create();
        $ticket->status_changed_at = Carbon::parse('2025-12-24 12:00:00');

        expect(fn() => $ticket->transition('escalate'))->toThrow(DirectWriteException::class, 'its [status_changed_at] records when its [status] entered its state');
        expect($ticket->fresh()->priority)->toBe(TicketPriority::Normal);
    });

    it('rejects creating a model with the column set outside a bypass', function () {
        expect(fn() => Ticket::create(['status_changed_at' => '2025-12-24 12:00:00']))->toThrow(
            DirectWriteException::class,
            'Cannot create ' . Ticket::class . ' with [status_changed_at] set: it records when its [status] entered its state, and only Interlock may write it, unless a bypass is active.',
        );

        expect(Ticket::count())->toBe(0);
    });

    it('rejects a mass write of the column outside a bypass, and writes nothing', function (string $method, Closure $write) {
        $ticket = Ticket::create();

        expect(fn() => $write(Ticket::query(), $ticket))->toThrow(
            DirectWriteException::class,
            'Cannot ' . $method . '() ' . Ticket::class . ' through its query builder: it writes the entered-at column [status_changed_at], which may only change in a transition. Apply a transition to each model instead.',
        );

        expect(Ticket::count())->toBe(1)
            ->and(storedEnteredAt($ticket))->toBe('2026-03-01 09:00:00');
    })->with([
        'update()' => ['update', fn(Builder $tickets) => $tickets->update(['status_changed_at' => '2025-12-24 12:00:00'])],
        'insert()' => ['insert', fn(Builder $tickets) => $tickets->insert(['status' => 'open', 'priority' => 'normal', 'status_changed_at' => '2025-12-24 12:00:00'])],
        'upsert()' => ['upsert', fn(Builder $tickets, Ticket $ticket) => $tickets->upsert([['id' => $ticket->getKey(), 'status' => 'open', 'priority' => 'normal', 'status_changed_at' => '2025-12-24 12:00:00']], ['id'], ['status_changed_at'])],
        'increment()' => ['increment', fn(Builder $tickets) => $tickets->increment('replies', 1, ['status_changed_at' => '2025-12-24 12:00:00'])],
    ]);
});

describe('mass writes', function () {
    it('sets the column when a bypassed mass write changes the state', function (Closure $write) {
        $ticket = Ticket::create();
        Carbon::setTestNow('2026-03-02 10:00:00');

        Interlock::bypass('Fix tickets', fn() => $write(Ticket::query(), $ticket));

        expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved)
            ->and(storedEnteredAt($ticket))->toBe('2026-03-02 10:00:00');
    })->with([
        'update()' => [fn(Builder $tickets) => $tickets->update(['status' => TicketStatus::Resolved])],
        'update() with a qualified column' => [fn(Builder $tickets) => $tickets->update(['tickets.status' => TicketStatus::Resolved])],
        'upsert()' => [fn(Builder $tickets, Ticket $ticket) => $tickets->upsert([['id' => $ticket->getKey(), 'status' => 'resolved', 'priority' => 'normal']], ['id'])],
        'upsert() updating only the state' => [fn(Builder $tickets, Ticket $ticket) => $tickets->upsert([['id' => $ticket->getKey(), 'status' => 'resolved', 'priority' => 'normal']], ['id'], ['status'])],
        'increment()' => [fn(Builder $tickets) => $tickets->increment('replies', 1, ['status' => TicketStatus::Resolved])],
        'incrementEach()' => [fn(Builder $tickets) => $tickets->incrementEach(['replies' => 1], ['status' => TicketStatus::Resolved])],
        'updateOrInsert()' => [fn(Builder $tickets, Ticket $ticket) => $tickets->updateOrInsert(['id' => $ticket->getKey()], ['status' => 'resolved'])],
    ]);

    it('sets only the columns of the state attributes it writes', function () {
        $ticket = Ticket::create();
        Carbon::setTestNow('2026-03-02 10:00:00');

        Interlock::bypass('Fix tickets', fn() => Ticket::query()->update(['priority' => TicketPriority::Urgent]));

        expect(storedEnteredAt($ticket))->toBe('2026-03-01 09:00:00')
            ->and(storedEnteredAt($ticket, 'priority_changed_at'))->toBe('2026-03-02 10:00:00');
    });

    it('keeps the value a bypassed mass write sets the column to', function () {
        $ticket = Ticket::create();

        Interlock::bypass('Import tickets', fn() => Ticket::query()->update(['status' => TicketStatus::Resolved, 'status_changed_at' => '2025-12-24 12:00:00']));

        expect(storedEnteredAt($ticket))->toBe('2025-12-24 12:00:00');
    });

    it('names the column in the bypassed mass write it was written by', function () {
        Event::fake([BypassedMassWrite::class]);
        Ticket::create();

        Interlock::bypass('Import tickets', fn() => Ticket::query()->update(['status_changed_at' => '2025-12-24 12:00:00']));

        Event::assertDispatched(BypassedMassWrite::class, fn(BypassedMassWrite $event) => $event->attributes === ['status_changed_at']);
    });

    it('leaves the column alone when a mass write leaves the state alone', function () {
        $ticket = Ticket::create();
        Carbon::setTestNow('2026-03-02 10:00:00');

        Ticket::query()->update(['subject' => 'Another subject']);
        Ticket::query()->increment('replies');

        expect(storedEnteredAt($ticket))->toBe('2026-03-01 09:00:00');
    });

    it('sets the column on every inserted row that does not set it', function () {
        Ticket::insert([['status' => 'open', 'priority' => 'normal'], ['status' => 'open', 'priority' => 'normal']]);
        Interlock::bypass('Import tickets', fn() => Ticket::insert([
            ['status' => 'resolved', 'priority' => 'normal', 'status_changed_at' => '2025-12-24 12:00:00'],
            ['status' => 'resolved', 'priority' => 'urgent'],
        ]));
        Ticket::query()->insertGetId(['status' => 'open', 'priority' => 'normal']);

        expect(Ticket::query()->toBase()->orderBy('id')->get(['status_changed_at', 'priority_changed_at'])->map(fn(object $row) => (array) $row)->all())->toBe([
            ['status_changed_at' => '2026-03-01 09:00:00', 'priority_changed_at' => '2026-03-01 09:00:00'],
            ['status_changed_at' => '2026-03-01 09:00:00', 'priority_changed_at' => '2026-03-01 09:00:00'],
            ['status_changed_at' => '2025-12-24 12:00:00', 'priority_changed_at' => '2026-03-01 09:00:00'],
            ['status_changed_at' => '2026-03-01 09:00:00', 'priority_changed_at' => '2026-03-01 09:00:00'],
            ['status_changed_at' => '2026-03-01 09:00:00', 'priority_changed_at' => '2026-03-01 09:00:00'],
        ]);
    });
});

describe('replicate()', function () {
    it('clears the column on the copy, so saving it records when the copy was created', function () {
        $ticket = Ticket::create()->transition('resolve');
        Carbon::setTestNow('2026-03-02 10:00:00');

        $copy = $ticket->replicate();

        expect($copy->status_changed_at)->toBeNull()
            ->and($copy->priority_changed_at)->toBeNull();

        $copy->save();

        expect(storedEnteredAt($copy))->toBe('2026-03-02 10:00:00');
    });

    it('keeps the column a replicating listener sets', function () {
        Ticket::replicating(function (Ticket $copy) {
            $copy->status_changed_at = Carbon::parse('2025-12-24 12:00:00');
        });

        $copy = Ticket::create()->replicate();

        expect($copy->status_changed_at?->toDateTimeString())->toBe('2025-12-24 12:00:00');

        Interlock::bypass('Copy a ticket with its time in state', fn() => $copy->save());

        expect(storedEnteredAt($copy))->toBe('2025-12-24 12:00:00');
    });
});
