<?php

use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineForUncastAttribute;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithoutFromStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithoutTargetState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithoutFromStates;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithoutTargetState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithUncastStateAttribute;

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

it('is an Interlock exception', function () {
    expect(rescue(fn() => (new PostWithoutTargetState())->transition('submit'), fn(Throwable $e) => $e, report: false))
        ->toBeInstanceOf(InterlockException::class);
});
