# Fixtures

The fixture domains from the spec (`.scratch/interlock-v1/spec.md`, "Testing Decisions"). Tests set up through these models and their factories, never through Interlock's internal classes.

- `BlogPosts/`: the blog post domain. Namespace `RobertBoes\Interlock\Tests\Fixtures\BlogPosts`.
- `Orders/`: the order domain. Namespace `RobertBoes\Interlock\Tests\Fixtures\Orders`.
- `InvalidDefinitions/`: models whose machines are declared wrongly, for the errors raised when the graph is compiled. They share the `posts` and `orders` tables.
- `UnsoundMachines/`: models whose machines compile but are deliberately broken (a dead end, an unreachable state, a final state with an outgoing transition, duplicate transition names), for the soundness check. Nothing reads their table.
- `Discovery/`: models for discovery and explicit registration, kept outside the paths the discovery tests scan. They share the `posts` and `orders` tables.
- `database/migrations/`: the fixture tables, loaded by `TestCase`.
