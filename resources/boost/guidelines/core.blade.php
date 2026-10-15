## Laravel Interlock

Interlock (`robertboes/laravel-interlock`) keeps an Eloquent model's state attribute consistent with a declared state machine. State changes only through named transitions; any other write to the state attribute or a guarded attribute throws `DirectWriteException`. Every transition writes with compare-and-swap, so a concurrent change makes it fail instead of overwriting.

### Declaring a machine

- States are the cases of a backed enum. Cast the state attribute to that enum; the cast is required.
- A machine extends `StateMachine`, declares `#[InitialStates]` and `#[FinalStates]`, and returns its transitions from `transitions()`. Each transition has one or more from-states and exactly one target state, and its name is unique across all machines on the model.
- Link it to the model with `#[UseStateMachine('status', OrderStatusMachine::class)]` and the `HasStateMachines` trait. Repeat the attribute for each state attribute.
- A transition that needs input is a class extending `Transition`, with `#[FromStates]`, `#[TargetState]` and a typed constructor. Its name is the class name in snake case (`CreateLabel` is `create_label`).
- An attribute only certain transitions may write is declared on the machine with `#[GuardedAttribute('tracking_code', Ship::class)]`.

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
            $this->transition('ship')->from(OrderStatus::Preparing)->to(OrderStatus::Shipped),
            $this->transition('cancel')->from(OrderStatus::Pending, OrderStatus::Preparing)->to(OrderStatus::Cancelled),
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
- Put side effects of a transition in its `afterCommit` step (fluent `->afterCommit()` or an `afterCommit()` method), never directly after `transition()` inside a transaction. Database writes that belong to the transition go in `apply`.

@verbatim
<code-snippet name="Apply transitions" lang="php">
$order->transition('cancel');
$order->transition(new Ship($trackingCode));
$post->fill($request->validated())->transition('publish');

$order->stageTransition(new Ship($trackingCode))
    ->stageTransition('capture_payment')
    ->save();
</code-snippet>
@endverbatim

### Failures, guards and authorisation

- `StaleStateException` (409): the stored state changed since the model was loaded. Nothing was written. Reload the model and decide again; do not catch and retry blindly. Use `lockForUpdate()` to wait instead of failing.
- `NotAFromStateException` (409): the current state is not a from-state, as with a double-submitted form.
- `FailedGuardException` (422): a guard failed. Declare guards with `->guard('has_title', fn(Post $post) => filled($post->title), 'A post needs a title.')` or `#[Guard('message')]` on a method of a class-based transition. Match on `$e->guard`, not on the message.
- `UnauthorisedTransitionException` (403): the actor is not authorised. Declare it with `->authorise(fn(?User $actor, Expense $expense) => ...)`, a Gate ability name, an `authorise()` method or `#[Authorise('ability')]`. The actor defaults to the authenticated user; pass one with `transition('approve', $manager)` or `transition('approve', null)`.
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

Use the tappable scopes; they expand parent states to their children. Pass `stateAttribute:` when one enum backs several machines on the model. With the opt-in `HasStateScopes` trait, the same scopes are `whereInState()`, `whereNotInState()` and `whereInFromStateOf()`.

@verbatim
<code-snippet name="Query by state" lang="php">
use RobertBoes\Interlock\Scopes\InFromStateOf;
use RobertBoes\Interlock\Scopes\InState;
use RobertBoes\Interlock\Scopes\NotInState;

Order::query()->tap(new InState(OrderStatus::Preparing))->get();
Order::query()->tap(new NotInState([OrderStatus::Delivered, OrderStatus::Cancelled]))->get();
Order::query()->tap(new InFromStateOf('ship'))->get();
Document::query()->tap(new InState(Approval::Approved, stateAttribute: 'legal_status'))->get();
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
</code-snippet>
@endverbatim

- Triggers run through `interlock:triggers`, which Interlock schedules every minute, so the scheduler and a queue worker must run.
- A triggered transition runs without an actor and skips authorisation; its guards still run. For a transition only the trigger may apply, use `->authorise(fn() => false)`. Never authorise `$actor === null` to mean "only the trigger": guests are null actors too.
- `Builder` is `Illuminate\Database\Eloquent\Builder`. The transition's from-states are added to the condition, so do not repeat them.
- A class-based transition declares a static `trigger(Builder $query)` method instead. A transition whose constructor has required parameters cannot have a trigger.

### Where enforcement stops

Enforcement covers everything that goes through Eloquent. `DB::table()`, `toBase()` and raw SQL (`DB::statement()`, `DB::update()`) bypass it entirely. Never use them to change a state attribute or guarded attribute.

### Testing

- Create models in any state with factories: `Order::factory()->create(['status' => OrderStatus::Shipped])`. Everything else stays enforced, so tests catch illegal transitions.
- Assert on transitions with `Interlock::assertTransitioned($order, 'ship')` and `Interlock::assertNotTransitioned($order)`, not by reading the state column.
- `Interlock::fake()` skips `apply`, `afterCommit`, guards and authorisation for the rest of the test, to test the calling code in isolation.
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

`php artisan interlock:signalbox` exports every machine as Mermaid. Use `--format=xstate` or `--format=xstate-ts --output=resources/js/machines.ts` for the frontend, or pass a machine class to export one.
