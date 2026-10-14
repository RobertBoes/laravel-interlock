<?php

namespace RobertBoes\Interlock;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Discovery\ModelRegistry;

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
     * Every interlocked model in the app, with its state machines keyed by state attribute.
     *
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>
     */
    public static function models(): array
    {
        return Container::getInstance()->make(ModelRegistry::class)->models();
    }
}
