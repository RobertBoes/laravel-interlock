<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\NotAFromStateException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Exceptions\UnauthorisedTransitionException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithoutTargetState;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

beforeEach(function () {
    Route::post('/expenses/{id}/{transition}', function (string $id, string $transition) {
        $expense = Expense::findOrFail($id);
        $expense->fill(request()->only('rejection_reason'));

        return $expense->transition($transition);
    });
});

function expenseAwaitingReview(): Expense
{
    return Expense::create(['amount' => 4200])->transition('submit');
}

/**
 * Another request changes the stored state between loading the expense and writing its transition.
 */
function rejectElsewhereBeforeTheWrite(Expense $expense): void
{
    Event::listen(Transitioning::class, fn() => DB::table('expenses')->where('id', $expense->getKey())->update(['status' => ExpenseStatus::Rejected->value]));
}

it('responds 403 when the actor may not apply the transition', function () {
    $expense = expenseAwaitingReview();

    $this->actingAs(Employee::staff())
        ->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertForbidden()
        ->assertJson(['message' => 'You may not apply transition [reject].']);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('responds 422 with the guard\'s message when a guard fails', function () {
    $expense = expenseAwaitingReview();

    $this->actingAs(Employee::manager())
        ->postJson("/expenses/{$expense->getKey()}/reject")
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Give a reason for rejecting the expense.']);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('responds 409 when the state is stale', function () {
    $expense = expenseAwaitingReview();
    rejectElsewhereBeforeTheWrite($expense);

    $this->actingAs(Employee::manager())
        ->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertConflict()
        ->assertExactJson(['message' => 'This record changed since it was loaded. Reload it and try again.']);
});

it('reports stale state with the model, the transition and the states as its context', function () {
    $expense = expenseAwaitingReview();
    rejectElsewhereBeforeTheWrite($expense);
    Log::spy();

    $this->actingAs(Employee::manager())
        ->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertConflict();

    $expected = [
        'model' => Expense::class,
        'key' => $expense->getKey(),
        'transition' => 'reject',
        'state_attribute' => 'status',
        'loaded_state' => 'submitted',
        'stored_state' => 'rejected',
        'row_deleted' => false,
    ];
    Log::shouldHaveReceived('error')->withArgs(fn(string $message, array $context) => $context['exception'] instanceof StaleStateException
        && Arr::only($context, array_keys($expected)) === $expected);
});

it('responds 409 when the transition does not start from the current state, as when a form is submitted twice', function () {
    $expense = expenseAwaitingReview();
    $this->actingAs(Employee::manager());

    $this->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])->assertOk();

    $this->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertConflict()
        ->assertExactJson(['message' => 'This transition can\'t be applied to the record in its current state.']);
});

it('reports a transition from the wrong state with the model, the transition and the states as its context', function () {
    $expense = Expense::create(['amount' => 4200]);
    Log::spy();

    $this->actingAs(Employee::manager())
        ->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertConflict();

    $expected = [
        'model' => Expense::class,
        'key' => $expense->getKey(),
        'transition' => 'reject',
        'state_attribute' => 'status',
        'current_state' => 'draft',
        'from_states' => ['submitted'],
    ];
    Log::shouldHaveReceived('error')->withArgs(fn(string $message, array $context) => $context['exception'] instanceof NotAFromStateException
        && Arr::only($context, array_keys($expected)) === $expected);
});

it('responds 200 when the transition applies', function () {
    $expense = expenseAwaitingReview();

    $this->actingAs(Employee::manager())
        ->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertOk()
        ->assertJson(['status' => 'rejected', 'rejection_reason' => 'No receipt']);
});

it('responds 500 to programming errors', function (string $exception, Closure $mistake) {
    Exceptions::fake();
    Route::post('/mistake', fn() => $mistake());

    $this->postJson('/mistake')->assertInternalServerError();

    Exceptions::assertReported($exception);
})->with([
    'a direct write' => [DirectWriteException::class, fn() => Post::create()->update(['status' => PostStatus::Published])],
    'a second transition staged on one machine' => [IllegalTransitionException::class, fn() => Post::create()->stageTransition('submit')->stageTransition('submit')],
    'an unknown transition' => [UnknownTransitionException::class, fn() => Post::create()->transition('shpi')],
    'an invalid definition' => [InvalidDefinitionException::class, fn() => (new PostWithoutTargetState())->transition('submit')],
]);

it('lets an app render a failed guard its own way', function () {
    app(ExceptionHandler::class)->renderable(fn(FailedGuardException $e) => response()->json(['errors' => ['guard' => [$e->guard]]], 400));
    $expense = expenseAwaitingReview();

    $this->actingAs(Employee::manager())
        ->postJson("/expenses/{$expense->getKey()}/reject")
        ->assertBadRequest()
        ->assertExactJson(['errors' => ['guard' => ['has_rejection_reason']]]);
});

it('lets an app render stale state its own way', function () {
    app(ExceptionHandler::class)->renderable(fn(StaleStateException $e) => response()->json(['message' => 'Someone else got there first.'], 409));
    $expense = expenseAwaitingReview();
    rejectElsewhereBeforeTheWrite($expense);

    $this->actingAs(Employee::manager())
        ->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertConflict()
        ->assertExactJson(['message' => 'Someone else got there first.']);
});

it('lets an app render an unauthorised transition its own way, like any authorisation failure', function () {
    app(ExceptionHandler::class)->renderable(fn(AccessDeniedHttpException $e) => $e->getPrevious() instanceof UnauthorisedTransitionException
        ? response()->json(['message' => "Ask a manager to {$e->getPrevious()->transition} it."], 403)
        : null);
    $expense = expenseAwaitingReview();

    $this->actingAs(Employee::staff())
        ->postJson("/expenses/{$expense->getKey()}/reject", ['rejection_reason' => 'No receipt'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Ask a manager to reject it.']);
});
