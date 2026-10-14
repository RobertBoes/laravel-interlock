<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Exceptions\InvalidBypassException;
use RobertBoes\Interlock\Exceptions\InvalidFakeException;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Graph\SoundnessCheck;
use RobertBoes\Interlock\Testing\TransitionLog;

final class Interlock
{
    /**
     * Register interlocked models that discovery does not scan, such as models from packages or modules. Call it from a service provider's boot method.
     *
     * @param  class-string<Model>  ...$models
     */
    public static function registerModels(string ...$models): void
    {
        Container::getInstance()->make(ModelRegistry::class)->register(array_values($models));
    }

    /**
     * Allow direct writes to state attributes and guarded attributes while the callback runs, for seeders, imports and data fixes. Each bypassed write to a state attribute dispatches BypassedWrite with the reason.
     *
     * @template TReturn
     *
     * @param  string  $reason  why the writes are made, such as "Import posts from the old blog"
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function bypass(string $reason, Closure $callback): mixed
    {
        return Container::getInstance()->make(BypassScope::class)->run($reason, $callback);
    }

    /**
     * Allow direct writes for the rest of the current test. Tests stay enforced unless they opt in; factories bypass without it.
     */
    public static function bypassForTest(string $reason = 'test'): void
    {
        $app = Container::getInstance();

        if (! $app instanceof Application || ! $app->runningUnitTests()) {
            throw InvalidBypassException::outsideTests();
        }

        $app->make(BypassScope::class)->bypassUntilAppEnds($reason);
    }

    /**
     * Whether a state is the given state, or nested within it: `Interlock::isWithin($order->status, OrderStatus::Preparing)`.
     */
    public static function isWithin(BackedEnum $state, BackedEnum $other): bool
    {
        return $state::class === $other::class
            && Container::getInstance()->make(GraphRepository::class)->stateTree($state::class)->isWithin($state, $other);
    }

    /**
     * Assert that every registered state machine is sound: no unreachable states, no states without a way out that are not final, no final states with outgoing transitions, and no definition the graph rejects, such as duplicate transition names.
     *
     * @param  class-string<Model>  ...$models  the models to check; every registered model when none are given
     */
    public static function assertMachinesAreSound(string ...$models): void
    {
        $problems = Container::getInstance()->make(SoundnessCheck::class)->problems(
            $models === [] ? array_keys(self::models()) : array_values($models),
        );

        $message = sprintf('Interlock found %d %s in the state machines:', count($problems), Str::plural('problem', count($problems)))
            . implode('', array_map(fn(string $problem) => PHP_EOL . '- ' . $problem, $problems));

        Assert::assertThat($problems, Assert::isEmpty(), $message);
    }

    /**
     * Skip the apply and after-commit steps of every transition for the rest of the current test, to test the code
     * that applies them in isolation. Transitions still check their from-state, write their target state with the
     * model's other changes and dispatch their events, and the transition assertions see them as usual.
     */
    public static function fake(): void
    {
        $app = Container::getInstance();

        if (! $app instanceof Application || ! $app->runningUnitTests()) {
            throw InvalidFakeException::outsideTests();
        }

        $app->make(TransitionLog::class)->fake();
    }

    /**
     * Assert that the model was transitioned by the named transition in this test: the transition committed for the
     * model's row, applied through this instance or any other.
     */
    public static function assertTransitioned(Model $model, string $transition): void
    {
        $applied = self::transitionsAppliedTo($model, $transition);

        Assert::assertContains($transition, $applied, sprintf(
            'The expected transition [%s] was not applied to %s. %s',
            $transition,
            self::describe($model),
            $applied === [] ? 'No transitions were applied to it.' : sprintf('The transitions applied to it were [%s].', implode(', ', $applied)),
        ));
    }

    /**
     * Assert that the model was not transitioned by the named transition in this test, or by any transition when no
     * name is given.
     */
    public static function assertNotTransitioned(Model $model, ?string $transition = null): void
    {
        $applied = self::transitionsAppliedTo($model, $transition);

        if ($transition === null) {
            Assert::assertEmpty($applied, sprintf('Unexpected transitions were applied to %s: [%s].', self::describe($model), implode(', ', $applied)));

            return;
        }

        Assert::assertNotContains($transition, $applied, sprintf('The unexpected transition [%s] was applied to %s.', $transition, self::describe($model)));
    }

    /**
     * Every interlocked model in the app, with its state machines keyed by state attribute.
     *
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>
     */
    public static function models(): array
    {
        return Container::getInstance()->make(ModelRegistry::class)->models();
    }

    /**
     * The transitions committed for the model's row in this test, oldest first. Checks first that the model has a
     * transition by the given name, so a misspelt name fails instead of passing an assertNotTransitioned().
     *
     * @return list<string>
     */
    private static function transitionsAppliedTo(Model $model, ?string $transition): array
    {
        if ($transition !== null) {
            Container::getInstance()->make(GraphRepository::class)->for($model)->transition($transition);
        }

        return Container::getInstance()->make(TransitionLog::class)->transitionsOf($model);
    }

    private static function describe(Model $model): string
    {
        return sprintf('%s [%s]', $model::class, $model->getKey());
    }
}
