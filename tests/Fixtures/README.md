# Fixtures

The fixture domains from the spec (`.scratch/interlock-v1/spec.md`, "Testing Decisions"). Tests set up through these models and their factories, never through Interlock's internal classes.

- `BlogPosts/`: the blog post domain. Namespace `RobertBoes\Interlock\Tests\Fixtures\BlogPosts`.
- `Orders/`: the order domain, with two machines (`status` and `payment_status`). Namespace `RobertBoes\Interlock\Tests\Fixtures\Orders`.
- `Contracts/`: a contract whose `legal_status` and `finance_status` machines are both backed by one `Approval` enum, with different graphs. Namespace `RobertBoes\Interlock\Tests\Fixtures\Contracts`.
- `Expenses/`: an expense claim whose transitions declare guards and authorization in every form: fluent guards, guard methods, an authorize closure, an authorize method and Gate abilities. `Employee` is the actor, never stored. Namespace `RobertBoes\Interlock\Tests\Fixtures\Expenses`.
- `CustomBuilders/`: orders with an app's own Eloquent builder, set through `newEloquentBuilder()` or the builder attribute, with and without Interlock's builder trait. They share the `orders` table.
- `InvalidDefinitions/`: models whose machines are declared wrongly, for the errors raised when the graph is compiled. They share the `posts` and `orders` tables.
- `UnsoundMachines/`: models whose machines compile but are deliberately broken (a dead end, an unreachable state, a final state with an outgoing transition, duplicate transition names), for the soundness check. Nothing reads their table.
- `Exports/`: a claim backed by an integer enum, whose `settle` transition has two guards, for the shapes the XState export has to encode. Nothing reads its table. Namespace `RobertBoes\Interlock\Tests\Fixtures\Exports`.
- `Discovery/`: models for discovery and explicit registration, kept outside the paths the discovery tests scan. They share the `posts` and `orders` tables.
- `LockedPosts/`: a post following the README's "scheduled and locked" pattern: once scheduled, its only way out is the triggered `publish`, and only `schedule` may write its `publish_at`. It shares the `posts` table and the blog post's `PostStatus` enum.
- `Feeds/`: a post from a high-volume feed, whose `publish` trigger fans out. Its condition is an invokable scope class, `PublishAtHasPassed`, rather than a closure. Kept outside `BlogPosts/` so the tests that scan it don't see it. It shares the `posts` table.
- `Returns/`: an order whose only transition, `return_to_sender`, takes a `Shipment` model as input, which the container could build empty. It shares the `orders` table and the order's `OrderStatus` enum.
- `LegacyOrders/`: an order whose machine is also called `OrderStatusMachine`, in another namespace, for the XState export of every machine refusing two machines with the same ID. It shares the `orders` table and the order's `OrderStatus` enum.
- `TriggerOnlyPosts/`: a post whose triggered `publish` authorizes no one, so only its trigger applies it. It shares the `posts` table and the blog post's `PostStatus` enum.
- `History/`: a history recorder that keeps its records in memory, for swapping the recorder through the config. Namespace `RobertBoes\Interlock\Tests\Fixtures\History`.
- `database/migrations/`: the fixture tables, loaded by `TestCase`, plus the package's own history migration.

Triggers: the post's `publish` once `publish_at` has passed, the expense's guarded `submit` 30 days after the expense was created, and the order's class-based `cancel` for orders still unpaid after 3 days, which fans out. The feed post's `publish` fans out too.

History is on for the post's and the trigger-only post's `status` machines, the order's `status` machine and both contract machines. The order's `payment_status` machine has it off.

One model lives outside this directory: `tests/MisdeclaredModels/PostWithoutHasStateMachines` declares a machine without using `HasStateMachines`, and discovery fails on any path that holds it, so it is kept out of the paths the discovery tests scan recursively.
