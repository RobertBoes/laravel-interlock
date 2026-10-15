<?php

use Illuminate\Support\Facades\Exceptions;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\InvalidSystemScopeException;
use RobertBoes\Interlock\Exceptions\SystemOnlyTransitionException;
use RobertBoes\Interlock\Exceptions\TriggerOnlyTransitionException;
use RobertBoes\Interlock\Exceptions\UnauthorizedTransitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Deletions\DeletionRequest;
use RobertBoes\Interlock\Tests\Fixtures\Deletions\DeletionStatus;
use RobertBoes\Interlock\Tests\Fixtures\Deletions\Fail;
use RobertBoes\Interlock\Tests\Fixtures\Deletions\ProcessDeletion;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithTransitionSystemOnlyAndAuthorized;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithTransitionSystemOnlyAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionClassSystemOnlyAndAuthorized;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionClassSystemOnlyAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionSystemOnlyAndAuthorized;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionSystemOnlyAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionSystemOnlyAndAuthorized;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionSystemOnlyAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\TriggerOnlyPosts\TriggerOnlyPost;

/**
 * @param  array<string, mixed>  $attributes
 */
function processingDeletion(array $attributes = []): DeletionRequest
{
    $request = DeletionRequest::create($attributes);

    return Interlock::asSystem('Deletion job', fn() => $request->transition('start'));
}

function latestHistoryOf(DeletionRequest $request): mixed
{
    return $request->history()->latest('id')->first();
}

describe('the system scope', function () {
    it('runs the callback as system code and returns its result', function () {
        $request = processingDeletion();

        $result = Interlock::asSystem('Deletion job', fn() => $request->transition('complete'));

        expect($result)->toBe($request)
            ->and($request->fresh()->status)->toBe(DeletionStatus::Completed);
    });

    it('requires a reason', function (string $reason) {
        $ran = false;

        expect(fn() => Interlock::asSystem($reason, function () use (&$ran) {
            $ran = true;
        }))->toThrow(
            InvalidSystemScopeException::class,
            'System code needs a reason, such as "Deletion job", so history says why a transition was applied without authorization.',
        );

        expect($ran)->toBeFalse();
    })->with(['empty' => '', 'blank' => '   ']);

    it('stacks nested scopes, the innermost reason winning until it returns', function () {
        $inner = DeletionRequest::create();
        $outer = DeletionRequest::create();

        Interlock::asSystem('Reconcile stuck deletions', function () use ($inner, $outer) {
            Interlock::asSystem('Deletion job', fn() => $inner->transition('start'));
            $outer->transition('start');
        });

        expect(latestHistoryOf($inner)->system_reason)->toBe('Deletion job')
            ->and(latestHistoryOf($outer)->system_reason)->toBe('Reconcile stuck deletions');
    });

    it('ends once the callback throws', function () {
        $request = processingDeletion();

        rescue(fn() => Interlock::asSystem('Deletion job', fn() => throw new RuntimeException('Deletion failed')), report: false);

        expect(fn() => $request->transition('complete'))->toThrow(SystemOnlyTransitionException::class);
    });

    it('does not carry over to another app instance', function () {
        $request = processingDeletion();

        Interlock::asSystem('Deletion job', function () use ($request) {
            $this->refreshApplication();

            expect(fn() => $request->transition('complete'))->toThrow(SystemOnlyTransitionException::class);
        });
    });
});

describe('actors inside the scope', function () {
    it('applies a transition on behalf of no one by default, even when a user is authenticated', function () {
        $this->actingAs(Employee::manager(id: 7));
        $request = processingDeletion();

        Interlock::asSystem('Deletion job', fn() => $request->transition('complete'));

        expect(latestHistoryOf($request))
            ->actor_type->toBeNull()
            ->actor_id->toBeNull()
            ->system_reason->toBe('Deletion job');
    });

    it('records an explicit actor beside the reason', function () {
        $request = DeletionRequest::create();

        Interlock::asSystem('Admin processed now', fn() => $request->transition('start', Employee::manager(id: 7)));

        expect(latestHistoryOf($request))
            ->actor_type->toBe(Employee::class)
            ->actor_id->toBe(7)
            ->system_reason->toBe('Admin processed now');
    });

    it('offers transitions on behalf of no one by default', function () {
        $this->actingAs(Employee::manager());
        $request = DeletionRequest::create();

        expect(Interlock::asSystem('Deletion job', fn() => $request->availableTransitions()->pluck('name')->all()))->toBe(['start', 'cancel'])
            ->and($request->availableTransitions()->pluck('name')->all())->toBe(['cancel']);
    });
});

describe('authorization inside the scope', function () {
    it('skips authorization for every transition', function (?Employee $actor) {
        $request = DeletionRequest::create();

        Interlock::asSystem('Cancel abandoned requests', fn() => $request->transition('cancel', $actor));

        expect($request->fresh()->status)->toBe(DeletionStatus::Cancelled);
    })->with([
        'no actor' => [null],
        'a staff member' => fn() => Employee::staff(),
    ]);

    it('still runs the guards', function () {
        $request = processingDeletion();

        expect(fn() => Interlock::asSystem('Deletion job', fn() => $request->transition(new Fail())))->toThrow(
            FailedGuardException::class,
            'A failed deletion needs a reason.',
        );

        $request->failure_reason = 'Storage unreachable';
        Interlock::asSystem('Deletion job', fn() => $request->transition(new Fail()));

        expect($request->fresh()->status)->toBe(DeletionStatus::Failed);
    });

    it('still refuses a trigger-only transition', function () {
        $post = TriggerOnlyPost::create()->transition('submit');

        expect(fn() => Interlock::asSystem('Publish now', fn() => $post->transition('publish')))->toThrow(
            TriggerOnlyTransitionException::class,
            'Only its trigger applies transition [publish].',
        );

        expect($post->fresh()->status)->toBe(PostStatus::Ready);
    });

    it('counts a trigger as system code, so it applies a system-only transition that declares it', function () {
        config(['interlock.models' => [DeletionRequest::class]]);
        Exceptions::fake();
        $request = DeletionRequest::create(['process_after' => '2026-10-14 09:00:00']);

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        Exceptions::assertNothingReported();
        expect($request->fresh()->status)->toBe(DeletionStatus::Processing)
            ->and(latestHistoryOf($request))
            ->trigger_name->toBe('start')
            ->system_reason->toBeNull();
    });
});

describe('system-only transitions', function () {
    it('throws when one is applied outside the scope, for every actor', function (Closure $apply) {
        $request = processingDeletion();

        expect(thrownBy(fn() => $apply($request)))
            ->toBeInstanceOf(SystemOnlyTransitionException::class)
            ->toBeInstanceOf(UnauthorizedTransitionException::class)
            ->getMessage()->toBe('Only system code applies transition [complete]. Apply it inside Interlock::asSystem($reason, $callback).')
            ->status()->toBe(403)
            ->and($request->fresh()->status)->toBe(DeletionStatus::Processing)
            ->and($request->status)->toBe(DeletionStatus::Processing);
    })->with([
        'a guest' => [fn(DeletionRequest $request) => $request->transition('complete')],
        'on behalf of no one' => [fn(DeletionRequest $request) => $request->transition('complete', null)],
        'a manager' => [fn(DeletionRequest $request) => $request->transition('complete', Employee::manager())],
        'by its signal' => [fn(DeletionRequest $request) => $request->signal('complete')],
        'staged' => [fn(DeletionRequest $request) => $request->stageTransition('complete', null)->save()],
        'staged inside the scope and saved outside it' => [fn(DeletionRequest $request) => Interlock::asSystem('Deletion job', fn() => $request->stageTransition('complete'))->save()],
    ]);

    it('throws for a transition class marked #[SystemOnly] outside the scope', function () {
        $request = processingDeletion(['failure_reason' => 'Storage unreachable']);

        expect(fn() => $request->transition(new Fail(), Employee::manager()))->toThrow(
            SystemOnlyTransitionException::class,
            'Only system code applies transition [fail].',
        );
    });

    it('applies a staged one saved inside the scope', function () {
        $request = processingDeletion()->stageTransition('complete');

        Interlock::asSystem('Deletion job', fn() => $request->save());

        expect($request->fresh()->status)->toBe(DeletionStatus::Completed);
    });

    it('is never offered outside the scope', function (?Employee $actor) {
        $request = processingDeletion(['failure_reason' => 'Storage unreachable']);

        expect($request->availableTransitions($actor)->all())->toBe([]);
    })->with([
        'no one' => [null],
        'a manager' => fn() => Employee::manager(),
    ]);

    it('offers every transition whose guards pass inside the scope', function () {
        $request = processingDeletion();

        expect(Interlock::asSystem('Deletion job', fn() => $request->availableTransitions(Employee::staff())->pluck('name')->all()))->toBe(['complete']);

        $request->failure_reason = 'Storage unreachable';

        expect(Interlock::asSystem('Deletion job', fn() => $request->availableTransitions()->pluck('name')->all()))->toBe(['complete', 'fail']);
    });

    it('offers no trigger-only transition inside the scope', function () {
        $post = TriggerOnlyPost::create()->transition('submit');

        expect(Interlock::asSystem('Publish now', fn() => $post->availableTransitions()->pluck('name')->all()))->toBe(['schedule', 'retract']);
    });

    it('is still refused outside the scope while faked', function () {
        $request = processingDeletion();
        Interlock::fake();

        expect(fn() => $request->transition('complete', Employee::manager()))->toThrow(SystemOnlyTransitionException::class);
        Interlock::assertNotTransitioned($request, 'complete');

        Interlock::asSystem('Deletion job', fn() => $request->transition('complete'));
        Interlock::assertTransitioned($request, 'complete');
    });
});

describe('history', function () {
    it('records the reason of the scope a bypassed write was made in', function () {
        $request = DeletionRequest::create();

        Interlock::asSystem('Deletion job', fn() => Interlock::bypass('Fix a stuck deletion', fn() => $request->update(['status' => DeletionStatus::Completed])));

        expect(latestHistoryOf($request))
            ->bypass_reason->toBe('Fix a stuck deletion')
            ->system_reason->toBe('Deletion job')
            ->actor_id->toBeNull();
    });

    it('records no reason for a transition outside the scope', function () {
        $request = DeletionRequest::create();

        $request->transition('cancel', Employee::manager());

        expect(latestHistoryOf($request)->system_reason)->toBeNull();
    });
});

describe('the RunsAsSystem job middleware', function () {
    it('runs the job inside the scope, with its reason', function () {
        $this->actingAs(Employee::manager(id: 7));
        $request = processingDeletion();

        ProcessDeletion::dispatch($request->id);

        expect($request->fresh()->status)->toBe(DeletionStatus::Completed)
            ->and(latestHistoryOf($request))
            ->system_reason->toBe('Deletion job')
            ->actor_id->toBeNull();
    });

    it('ends the scope once the job has run', function () {
        $request = processingDeletion();
        ProcessDeletion::dispatch(processingDeletion()->id);

        expect(fn() => $request->transition('complete'))->toThrow(SystemOnlyTransitionException::class);
    });
});

describe('declaring a system-only transition', function () {
    it('rejects a fluent transition that combines systemOnly() with authorization', function (string $model, string $machine, string $other) {
        expect(fn() => (new $model())->transition('submit'))->toThrow(
            InvalidDefinitionException::class,
            "Transition [submit] in {$machine} calls both ->systemOnly() and {$other}. Keep one of them.",
        );
    })->with([
        '->authorize()' => [PostWithTransitionSystemOnlyAndAuthorized::class, MachineWithTransitionSystemOnlyAndAuthorized::class, '->authorize()'],
        '->withoutAuthorization()' => [PostWithTransitionSystemOnlyAndWithoutAuthorization::class, MachineWithTransitionSystemOnlyAndWithoutAuthorization::class, '->withoutAuthorization()'],
    ]);

    it('rejects a transition class that combines #[SystemOnly] with authorization', function (string $model, string $transition, string $other) {
        expect(fn() => (new $model())->transition($transition::name()))->toThrow(
            InvalidDefinitionException::class,
            "Transition {$transition} declares both #[SystemOnly] and {$other}. Keep one of them.",
        );
    })->with([
        'authorization' => [PostWithTransitionClassSystemOnlyAndAuthorized::class, TransitionSystemOnlyAndAuthorized::class, 'authorization'],
        '#[WithoutAuthorization]' => [PostWithTransitionClassSystemOnlyAndWithoutAuthorization::class, TransitionSystemOnlyAndWithoutAuthorization::class, '#[WithoutAuthorization]'],
    ]);
});
