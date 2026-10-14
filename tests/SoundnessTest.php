<?php

use PHPUnit\Framework\AssertionFailedError;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\MachineWithDeadEnd;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\MachineWithDuplicateTransitionNames;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\MachineWithFinalStateWithOutgoingTransition;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\MachineWithUnreachableState;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\TaskWithDeadEnd;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\TaskWithDuplicateTransitionNames;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\TaskWithFinalStateWithOutgoingTransition;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\TaskWithUnreachableState;

beforeEach(fn() => config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts', __DIR__ . '/Fixtures/Orders']]));

it('passes for sound machines, parent states included', function () {
    Interlock::assertMachinesAreSound();
});

it('fails for a state that is not final and has no way out', function () {
    config(['interlock.models' => [TaskWithDeadEnd::class]]);

    expect(fn() => Interlock::assertMachinesAreSound())->toThrow(
        AssertionFailedError::class,
        'Interlock found 1 problem in the state machines:' . PHP_EOL
        . '- ' . MachineWithDeadEnd::class . ' on ' . TaskWithDeadEnd::class . ' [status]: state [dropped] has no way out. No transition starts from it, and it is not a final state, so add a transition from it or declare it in #[FinalStates].',
    );
});

it('fails for a state that cannot be reached from the initial states', function () {
    config(['interlock.models' => [TaskWithUnreachableState::class]]);

    expect(fn() => Interlock::assertMachinesAreSound())->toThrow(
        AssertionFailedError::class,
        '- ' . MachineWithUnreachableState::class . ' on ' . TaskWithUnreachableState::class . ' [status]: state [dropped] is unreachable. No transitions lead to it from the initial states [todo].',
    );
});

it('fails for a final state with an outgoing transition', function () {
    config(['interlock.models' => [TaskWithFinalStateWithOutgoingTransition::class]]);

    expect(fn() => Interlock::assertMachinesAreSound())->toThrow(
        AssertionFailedError::class,
        '- ' . MachineWithFinalStateWithOutgoingTransition::class . ' on ' . TaskWithFinalStateWithOutgoingTransition::class . ' [status]: final state [done] has outgoing transition [reopen]. A final state is never left, so remove [reopen] or the state from #[FinalStates].',
    );
});

it('fails for two transitions with the same name', function () {
    config(['interlock.models' => [TaskWithDuplicateTransitionNames::class]]);

    expect(fn() => Interlock::assertMachinesAreSound())->toThrow(
        AssertionFailedError::class,
        '- ' . TaskWithDuplicateTransitionNames::class . ' has two transitions named [start], in ' . MachineWithDuplicateTransitionNames::class . '. Transition names are unique per model, so rename one of them.',
    );
});

it('reports every problem in every registered machine at once', function () {
    config(['interlock.models' => [TaskWithDeadEnd::class, TaskWithUnreachableState::class, TaskWithDuplicateTransitionNames::class]]);

    $failure = rescue(fn() => Interlock::assertMachinesAreSound(), fn(AssertionFailedError $e) => $e->getMessage(), report: false);

    expect($failure)
        ->toStartWith('Interlock found 3 problems in the state machines:')
        ->toContain(MachineWithDeadEnd::class . ' on ' . TaskWithDeadEnd::class . ' [status]: state [dropped] has no way out.')
        ->toContain(MachineWithUnreachableState::class . ' on ' . TaskWithUnreachableState::class . ' [status]: state [dropped] is unreachable.')
        ->toContain(TaskWithDuplicateTransitionNames::class . ' has two transitions named [start]');
});

it('checks only the given models when they are named', function () {
    config(['interlock.models' => [TaskWithDeadEnd::class]]);

    Interlock::assertMachinesAreSound(Post::class, Order::class);

    expect(fn() => Interlock::assertMachinesAreSound(Post::class, TaskWithUnreachableState::class))->toThrow(
        AssertionFailedError::class,
        'Interlock found 1 problem in the state machines:' . PHP_EOL
        . '- ' . MachineWithUnreachableState::class . ' on ' . TaskWithUnreachableState::class . ' [status]: state [dropped] is unreachable.',
    );
});

describe('the Pest expectation', function () {
    it('passes for the sound machines of every registered model', function () {
        expect(Interlock::models())->toHaveSoundStateMachines();
    });

    it('passes for the sound machines of one model, or a list of them', function () {
        expect(Order::class)->toHaveSoundStateMachines()
            ->and([Post::class, Order::class])->toHaveSoundStateMachines();
    });

    it('fails with the same problems as the assertion', function () {
        config(['interlock.models' => [TaskWithFinalStateWithOutgoingTransition::class]]);

        expect(fn() => expect(Interlock::models())->toHaveSoundStateMachines())->toThrow(
            AssertionFailedError::class,
            'Interlock found 1 problem in the state machines:' . PHP_EOL
            . '- ' . MachineWithFinalStateWithOutgoingTransition::class . ' on ' . TaskWithFinalStateWithOutgoingTransition::class . ' [status]: final state [done] has outgoing transition [reopen].',
        );
    });
});
