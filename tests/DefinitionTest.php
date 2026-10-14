<?php

use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineForUncastAttribute;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithFinalStateOfAnotherEnum;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineListingANonTransitionClass;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithNonTransitionClass;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionClassWithoutFromStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionClassWithoutTargetState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionWithoutFromStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionWithoutTargetState;
use RobertBoes\Interlock\Transition;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithInitialStateOfAnotherEnum;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithoutFromStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithoutInitialStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithoutTargetState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OtherStatus;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithFinalStateOfAnotherEnum;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithInitialStateOfAnotherEnum;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithoutFromStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithoutInitialStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithoutTargetState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithUncastStateAttribute;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\MachineWithDuplicateTransitionNames;
use RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines\TaskWithDuplicateTransitionNames;

it('rejects a machine without initial states', function () {
    expect(fn() => PostWithoutInitialStates::create(['status' => PostStatus::Draft]))->toThrow(
        InvalidDefinitionException::class,
        MachineWithoutInitialStates::class . ' declares no initial states. Add #[InitialStates(...)] to it with the states a model may be created in.',
    );
});

it('rejects an initial state of an enum the state attribute is not cast to', function () {
    expect(fn() => PostWithInitialStateOfAnotherEnum::create(['status' => PostStatus::Draft]))->toThrow(
        InvalidDefinitionException::class,
        MachineWithInitialStateOfAnotherEnum::class . ' declares initial state [open] of ' . OtherStatus::class . ', but ' . PostWithInitialStateOfAnotherEnum::class . ' casts [status] to ' . PostStatus::class . '. Use a case of ' . PostStatus::class . ' in #[InitialStates], or change the cast of [status].',
    );
});

it('rejects a final state of an enum the state attribute is not cast to', function () {
    expect(fn() => (new PostWithFinalStateOfAnotherEnum())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        MachineWithFinalStateOfAnotherEnum::class . ' declares final state [open] of ' . OtherStatus::class . ', but ' . PostWithFinalStateOfAnotherEnum::class . ' casts [status] to ' . PostStatus::class . '. Use a case of ' . PostStatus::class . ' in #[FinalStates], or change the cast of [status].',
    );
});

it('rejects two transitions with the same name', function () {
    expect(fn() => (new TaskWithDuplicateTransitionNames())->transition('finish'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [start] is declared twice in ' . MachineWithDuplicateTransitionNames::class . '. Transition names are unique per model, so rename one of them.',
    );
});

it('rejects a transition without a target state', function () {
    expect(fn() => (new PostWithoutTargetState())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [submit] in ' . MachineWithoutTargetState::class . ' has no target state. Call ->to() with the one state it leads to.',
    );
});

it('rejects a transition without from-states', function () {
    expect(fn() => (new PostWithoutFromStates())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [submit] in ' . MachineWithoutFromStates::class . ' has no from-states. Call ->from() with the states it may start from.',
    );
});

it('rejects a state attribute that is not cast to the enum of its states', function () {
    expect(fn() => (new PostWithUncastStateAttribute())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        MachineForUncastAttribute::class . ' uses states of ' . PostStatus::class . ', so ' . PostWithUncastStateAttribute::class . ' must cast [status] to ' . PostStatus::class . ' in its casts.',
    );
});

it('rejects a transition class without a target state attribute', function () {
    expect(fn() => (new PostWithTransitionClassWithoutTargetState())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionWithoutTargetState::class . ' has no target state. Add #[TargetState(...)] to it with the one state it leads to.',
    );
});

it('rejects a transition class without a from-states attribute', function () {
    expect(fn() => (new PostWithTransitionClassWithoutFromStates())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionWithoutFromStates::class . ' has no from-states. Add #[FromStates(...)] to it with the states it may start from.',
    );
});

it('rejects a listed class that does not extend the transition base class', function () {
    expect(fn() => (new PostWithNonTransitionClass())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        MachineListingANonTransitionClass::class . ' lists [' . Post::class . '] in its transitions, but it is not a class extending ' . Transition::class . '. List fluent transitions and transition classes.',
    );
});

it('is an Interlock exception', function () {
    expect(rescue(fn() => (new PostWithoutTargetState())->transition('submit'), fn(Throwable $e) => $e, report: false))
        ->toBeInstanceOf(InterlockException::class);
});
