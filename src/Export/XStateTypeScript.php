<?php

namespace RobertBoes\Interlock\Export;

use BackedEnum;
use Illuminate\Support\Str;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;

final class XStateTypeScript
{
    /**
     * @param  list<CompiledTransition>  $transitions
     */
    public static function of(CompiledMachine $machine, array $transitions): string
    {
        $id = XStateConfig::id($machine);
        $config = rtrim(XStateConfig::json(XStateConfig::of($machine, $transitions)));

        $states = array_map(fn(BackedEnum $state) => self::literal((string) $state->value), $machine->states->leaves());
        $events = array_map(fn(CompiledTransition $transition) => '{ type: ' . self::literal($transition->name) . ' }', $transitions);

        return implode(PHP_EOL . PHP_EOL, [
            sprintf('export const %s = %s as const;', Str::camel($id), $config),
            sprintf('export type %sState = %s;', $id, implode(' | ', $states)),
            sprintf('export type %sEvent = %s;', $id, $events === [] ? 'never' : implode(' | ', $events)),
        ]) . PHP_EOL;
    }

    private static function literal(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
