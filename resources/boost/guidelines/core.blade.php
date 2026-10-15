## Laravel Interlock

Interlock (`robertboes/laravel-interlock`) keeps an Eloquent model's state attribute consistent with a declared state machine. State changes only through named transitions; any other write to the state attribute or a guarded attribute throws `DirectWriteException`. Every transition writes with compare-and-swap, so a concurrent change makes it fail instead of overwriting.

### Declaring a machine

- States are the cases of a backed enum. Cast the state attribute to that enum; the cast is required.
- A machine extends `StateMachine`, declares `#[InitialStates]` and `#[FinalStates]`, and returns its transitions from `transitions()`. Each transition has one or more from-states and exactly one target state, and its name is unique across all machines on the model.
- Link it to the model with `#[UseStateMachine('status', OrderStatusMachine::class)]` and the `HasStateMachines` trait. Repeat the attribute for each state attribute.
- A transition that needs input is a class extending `Transition`, with `#[FromStates]`, `#[TargetState]` and a typed constructor. Its name is the class name in snake case (`CreateLabel` is `create_label`).
- Every transition must declare who may apply it by hand, or compiling the graph throws `InvalidDefinitionException`: authorization (`->authorize(...)`, an `authorize()` method or `#[Authorize('ability')]`), `->withoutAuthorization()` (`#[WithoutAuthorization]` on a class) for anyone, or a trigger and no authorization, so only the trigger applies it. Never declare both authorization and `withoutAuthorization()`.
- `withoutAuthorization()` means anyone: every user, guests, and jobs or commands with a null actor. Use authorization unless the transition really is open to all of them.
- An attribute only certain transitions may write is declared on the machine with `#[GuardedAttribute('tracking_code', Ship::class)]`.
- To know how long a model has been in its state, declare `enteredAt:` on the model: `#[UseStateMachine('status', TicketStatusMachine::class, enteredAt: 'status_changed_at')]`. Interlock sets that column on create and on every transition; never write it yourself outside a bypass. Its migration adds a nullable, indexed timestamp and backfills existing rows from `updated_at`. Do not keep a hand-written `*_changed_at` column or read `updated_at` as a stand-in for it.

@verbatim
<code-snippet name="Declare a state machine" lang="php">
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(OrderStatus::Pending)]
#[FinalStates(OrderStatus::Delivered, OrderStatus::Cancelled)]
class OrderStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('ship')->from(OrderStatus::Preparing)->to(OrderStatus::Shipped)->authorize('manage-orders'),
            $this->transition('cancel')->from(OrderStatus::Pending, OrderStatus::Preparing)->to(OrderStatus::Cancelled)->authorize('cancel-order'),
        ];
    }
}

#[UseStateMachine('status', OrderStatusMachine::class)]
class Order extends Model
{
    use HasStateMachines;

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }
}
</code-snippet>
@endverbatim

### Changing state

- Never assign the state attribute or a guarded attribute and save, and never pass them to `update()`, `fill()`, `create()` (outside an initial state) or a builder mass write. Use `transition()`, which writes immediately together with the model's other unsaved changes; there is no separate `save()`.
- To write transitions on several machines in one update, use `stageTransition()` and then `save()`.
- When one action means a different transition depending on the state, such as a customer's reply that moves an answered ticket back to the queue but reopens a closed one, declare a signal on those transitions with `->onSignal('reply')` (`#[OnSignal('reply')]` on a class) and call `$ticket->signal('reply', $user)`. Do not pick the transition by checking the state in the caller. `signal()` applies it like `transition()` and returns its name, or null when no transition answers the signal from the current state.
- Every transition answers a signal of its own name unless it declares another. At most one transition answers a signal from each state, signal names are unique per model, and a transition that takes input cannot declare a signal; compiling the graph throws `InvalidDefinitionException` otherwise.
- Put side effects of a transition in its `afterCommit` step (fluent `->afterCommit()` or an `afterCommit()` method), never directly after `transition()` inside a transaction. Database writes that belong to the transition go in `apply`.

@verbatim
<code-snippet name="Apply transitions" lang="php">
$order->transition('cancel');
$order->transition(new Ship($trackingCode));
$post->fill($request->validated())->transition('publish');

$order->stageTransition(new Ship($trackingCode))
    ->stageTransition('capture_payment')
    ->save();

$this->transition('customer_replied')->onSignal('reply')->from(TicketStatus::Answered)->to(TicketStatus::Open)->authorize('reply-to-ticket'),
$this->transition('reopen')->onSignal('reply')->from(TicketStatus::Closed)->to(TicketStatus::Open)->authorize('reply-to-ticket'),

$ticket->signal('reply', $user); // 'customer_replied', 'reopen' or null
</code-snippet>
@endverbatim

### Failures, guards and authorization

- `StaleStateException` (409): the stored state changed since the model was loaded. Nothing was written. Reload the model and decide again; do not catch and retry blindly. Use `lockForUpdate()` to wait instead of failing.
- `NotAFromStateException` (409): the current state is not a from-state, as with a double-submitted form.
- `FailedGuardException` (422): a guard failed. Declare guards with `->guard('has_title', fn(Post $post) => filled($post->title), 'A post needs a title.')` or `#[Guard('message')]` on a method of a class-based transition. Match on `$e->guard`, not on the message.
- `UnauthorizedTransitionException` (403): the actor is not authorized. Declare it with `->authorize(fn(?User $actor, Expense $expense) => ...)`, a Gate ability name, an `authorize()` method or `#[Authorize('ability')]`. The actor defaults to the authenticated user; pass one with `transition('approve', $manager)` or `transition('approve', null)`.
- `TriggerOnlyTransitionException` (403, extends `UnauthorizedTransitionException`): a transition with a trigger and no authorization was applied or staged by hand. Only its trigger applies it.
- Do not check states by hand before transitioning. Use `$model->availableTransitions($actor)` to show users what they can do.

### Bypass

Seeders, imports and data fixes that must set an arbitrary state run inside `Interlock::bypass()` with a reason. Model factories bypass automatically, so do not wrap factory calls.

@verbatim
<code-snippet name="Bypass for an import" lang="php">
use RobertBoes\Interlock\Interlock;

Interlock::bypass('Import orders from the old shop', function () use ($rows) {
    foreach ($rows as $row) {
        Order::create(['reference' => $row['reference'], 'status' => OrderStatus::from($row['status'])]);
    }
});
</code-snippet>
@endverbatim

### Querying by state

Use the tappable scopes; they expand parent states to their children. Pass `stateAttribute:` when one enum backs several machines on the model. `InStateFor` finds models that have been in a state for at least `minutes:`, `hours:`, `days:` or a `CarbonInterval`, by the `enteredAt` column. With the opt-in `HasStateScopes` trait, the same scopes are `whereInState()`, `whereNotInState()`, `whereInStateFor()` and `whereInFromStateOf()`.

@verbatim
<code-snippet name="Query by state" lang="php">
use RobertBoes\Interlock\Scopes\InFromStateOf;
use RobertBoes\Interlock\Scopes\InState;
use RobertBoes\Interlock\Scopes\InStateFor;
use RobertBoes\Interlock\Scopes\NotInState;

Order::query()->tap(new InState(OrderStatus::Preparing))->get();
Order::query()->tap(new NotInState([OrderStatus::Delivered, OrderStatus::Cancelled]))->get();
Order::query()->tap(new InFromStateOf('ship'))->get();
Document::query()->tap(new InState(Approval::Approved, stateAttribute: 'legal_status'))->get();
Ticket::query()->tap(new InStateFor(TicketStatus::Resolved, days: 7))->get();
</code-snippet>
@endverbatim

### Triggers instead of scheduled jobs

Do not write a scheduled job or command that finds models and changes their state. Declare a trigger on the transition: a query constraint under which Interlock applies it. To reschedule, change the data the condition reads, such as `publish_at`.

@verbatim
<code-snippet name="Trigger a transition" lang="php">
$this->transition('publish')
    ->from(PostStatus::Scheduled)
    ->to(PostStatus::Published)
    ->trigger(fn(Builder $query) => $query->where('publish_at', '<=', now())),

$this->transition('close')
    ->from(TicketStatus::Resolved)
    ->to(TicketStatus::Closed)
    ->trigger(new InStateFor(days: 7)),
</code-snippet>
@endverbatim

- Triggers run through `interlock:triggers`, which Interlock schedules every minute, so the scheduler and a queue worker must run.
- Do not add a backstop sweep or a watchdog command beside a trigger in case it stops firing. Schedule `interlock:triggers:check` with a heartbeat ping on success instead: it fails, naming the trigger, while a trigger is overdue (models match it, and no run finished within `interlock.triggers.overdue_after` minutes, or they have matched at the start of every run for longer). `php artisan interlock:triggers --status` and `Interlock::triggerStatus()` show each trigger's matching models and last run.
- A triggered transition runs without an actor and skips authorization; its guards still run. Leave authorization out for a transition only the trigger may apply: it is then trigger-only, and `availableTransitions()` never offers it. Add `->authorize(...)` or `->withoutAuthorization()` to let people apply it by hand as well. Never authorize `$actor === null` to mean "only the trigger": guests are null actors too.
- `Builder` is `Illuminate\Database\Eloquent\Builder`. The transition's from-states are added to the condition, so do not repeat them.
- For "in this state for N days" triggers, use `new InStateFor(days: N)` without states (add `stateAttribute:` on a model with several machines); the machine needs an `enteredAt` column. It works out the cutoff on every run. A duration from config must be read on every run too: `->trigger(fn(Builder $query) => $query->tap(new InStateFor(days: config('tickets.close_after_days'))))`.
- A class-based transition declares a static `trigger(Builder $query)` method instead. A transition whose constructor has required parameters cannot have a trigger.

### Where enforcement stops

Enforcement covers everything that goes through Eloquent. `DB::table()`, `toBase()` and raw SQL (`DB::statement()`, `DB::update()`) bypass it entirely. Never use them to change a state attribute or guarded attribute.

### Testing

- Create models in any state with factories: `Order::factory()->create(['status' => OrderStatus::Shipped])`. Everything else stays enforced, so tests catch illegal transitions.
- Assert on transitions with `Interlock::assertTransitioned($order, 'ship')` and `Interlock::assertNotTransitioned($order)`, not by reading the state column.
- `Interlock::fake()` skips `apply`, `afterCommit`, guards and authorization for the rest of the test, to test the calling code in isolation. A trigger-only transition applied by hand still throws `TriggerOnlyTransitionException`.
- `Interlock::bypassForTest('reason')` allows direct writes for the rest of one test only.
- Keep one test that checks every machine for unreachable states and dead ends:

@verbatim
<code-snippet name="Check machines are sound" lang="php">
use RobertBoes\Interlock\Interlock;

it('has sound state machines', function () {
    expect(Interlock::models())->toHaveSoundStateMachines();
});
</code-snippet>
@endverbatim

### Exporting

`php artisan interlock:signalbox` exports every machine as Mermaid. Use `--format=xstate` or `--format=xstate-ts --output=resources/js/machines.ts` for the frontend, or pass a machine class to export one. To show the machines to the user, use `--viz`, which prints XState JSON to paste into https://sketch.stately.ai.
