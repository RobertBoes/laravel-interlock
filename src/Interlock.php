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
use RobertBoes\Interlock\Triggers\TriggerMonitor;
use RobertBoes\Interlock\Triggers\TriggerStatus;

final class Interlock
{
    /**
     * For models discovery does not scan, such as models from packages or modules.
     *
     * @param  class-string<Model>  ...$models
     */
    public static function registerModels(string ...$models): void
    {
        Container::getInstance()->make(ModelRegistry::class)->register(array_values($models));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function bypass(string $reason, Closure $callback): mixed
    {
        return Container::getInstance()->make(BypassScope::class)->run($reason, $callback);
    }

    /**
     * Lasts for the rest of the current test; factories bypass without it.
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
     * Whether the state is the other state, or nested within it.
     */
    public static function isWithin(BackedEnum $state, BackedEnum $other): bool
    {
        return $state::class === $other::class
            && Container::getInstance()->make(GraphRepository::class)->stateTree($state::class)->isWithin($state, $other);
    }

    /**
     * @param  class-string<Model>  ...$models  every registered model when none are given
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
     * Skips the apply and after-commit steps for the rest of the test; transitions still write and dispatch events.
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
     * Matches transitions committed for the model's row, applied through this instance or any other.
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
     * Without a name, asserts that no transition was applied at all.
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
     * One entry per trigger of every registered model, counting the models that match each one now.
     *
     * @return list<TriggerStatus>
     */
    public static function triggerStatus(): array
    {
        return Container::getInstance()->make(TriggerMonitor::class)->statuses();
    }

    /**
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>
     */
    public static function models(): array
    {
        return Container::getInstance()->make(ModelRegistry::class)->models();
    }

    /**
     * Resolves the transition first, so a misspelt name fails instead of passing an assertNotTransitioned().
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
