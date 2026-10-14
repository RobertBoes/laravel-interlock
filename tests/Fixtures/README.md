# Fixtures

The fixture domains from the spec (`.scratch/interlock-v1/spec.md`, "Testing Decisions"). Tests set up through these models and their factories, never through Interlock's internal classes.

- `BlogPosts/`: the blog post domain. Namespace `RobertBoes\Interlock\Tests\Fixtures\BlogPosts`.
- `Orders/`: the order domain. Namespace `RobertBoes\Interlock\Tests\Fixtures\Orders`.
- `InvalidDefinitions/`: models whose machines are declared wrongly, for the errors raised when the graph is compiled. They share the `posts` table.
- `Discovery/`: models for discovery and explicit registration, kept outside the paths the discovery tests scan. They share the `posts` table.
- `database/migrations/`: the fixture tables, loaded by `TestCase`.
