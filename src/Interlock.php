<?php

namespace RobertBoes\Interlock;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Exceptions\InvalidBypassException;

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
     * Allow direct writes to state attributes while the callback runs, for seeders, imports and data fixes. Each bypassed write dispatches BypassedWrite with the reason.
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
     * Every interlocked model in the app, with its state machines keyed by state attribute.
     *
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>
     */
    public static function models(): array
    {
        return Container::getInstance()->make(ModelRegistry::class)->models();
    }
}
