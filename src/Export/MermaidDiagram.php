<?php

namespace RobertBoes\Interlock\Export;

use BackedEnum;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;

final class MermaidDiagram
{
    private const string INDENT = '    ';

    /**
     * @param  list<CompiledTransition>  $transitions
     */
    public static function of(CompiledMachine $machine, array $transitions): string
    {
        $lines = [
            'stateDiagram-v2',
            ...self::states($machine, null, self::INDENT),
            ...array_map(fn(BackedEnum $state) => self::INDENT . '[*] --> ' . $state->name, $machine->initialStates),
        ];

        foreach ($transitions as $transition) {
            foreach ($transition->fromStates as $fromState) {
                $lines[] = self::INDENT . sprintf('%s --> %s: %s', $fromState->name, $transition->targetState->name, $transition->name);
            }
        }

        foreach ($machine->finalStates as $finalState) {
            $lines[] = self::INDENT . $finalState->name . ' --> [*]';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return list<string>
     */
    private static function states(CompiledMachine $machine, ?BackedEnum $parent, string $indent): array
    {
        $lines = [];

        foreach ($machine->states->childrenOf($parent) as $state) {
            if (! $machine->states->isParent($state)) {
                $lines[] = $indent . $state->name;

                continue;
            }

            $lines = [
                ...$lines,
                $indent . 'state ' . $state->name . ' {',
                ...self::states($machine, $state, $indent . self::INDENT),
                $indent . '}',
            ];
        }

        return $lines;
    }
}
