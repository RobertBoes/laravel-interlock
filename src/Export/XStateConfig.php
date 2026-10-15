<?php

namespace RobertBoes\Interlock\Export;

use BackedEnum;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;
use stdClass;

/**
 * States are keyed by their enum value, the value a model stores, so a frontend can look up the state it was given.
 */
final class XStateConfig
{
    /**
     * @param  list<CompiledTransition>  $transitions
     * @return array<string, mixed>
     */
    public static function of(CompiledMachine $machine, array $transitions): array
    {
        $config = [
            'id' => self::id($machine),
            'initial' => self::key($machine->initialStates[0]),
            'states' => self::states($machine, $transitions, null),
        ];

        if ($machine->soleInitialState() === null) {
            $config['meta'] = ['initialStates' => array_map(self::key(...), $machine->initialStates)];
        }

        return $config;
    }

    /**
     * Every machine as a region of one parallel machine, so a visualizer draws them side by side.
     *
     * @param  array<array{CompiledMachine, list<CompiledTransition>}>  $machines
     * @return array<string, mixed>
     */
    public static function sideBySide(array $machines): array
    {
        $regions = new stdClass();

        foreach ($machines as [$machine, $transitions]) {
            $regions->{self::id($machine)} = self::of($machine, $transitions);
        }

        return ['id' => 'StateMachines', 'type' => 'parallel', 'states' => $regions];
    }

    public static function id(CompiledMachine $machine): string
    {
        return class_basename($machine->class);
    }

    public static function json(mixed $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    /**
     * An object, so states keyed by sequential integer values still encode as a JSON object.
     *
     * @param  list<CompiledTransition>  $transitions
     */
    private static function states(CompiledMachine $machine, array $transitions, ?BackedEnum $parent): stdClass
    {
        $states = new stdClass();

        foreach ($machine->states->childrenOf($parent) as $state) {
            $states->{self::key($state)} = self::state($machine, $transitions, $state);
        }

        return $states;
    }

    /**
     * XState needs an initial child for every parent state; nothing enters one without naming a child, so its first
     * child stands in. A leaf within a final parent state is final itself, as XState only marks leaf states final.
     *
     * @param  list<CompiledTransition>  $transitions
     */
    private static function state(CompiledMachine $machine, array $transitions, BackedEnum $state): stdClass
    {
        $node = new stdClass();
        $isParent = $machine->states->isParent($state);

        if ($isParent) {
            $node->initial = self::key($machine->states->childrenOf($state)[0]);
        } elseif ($machine->isFinalState($state)) {
            $node->type = 'final';
        }

        $events = self::events($machine, $transitions, $state);

        if ((array) $events !== []) {
            $node->on = $events;
        }

        if ($isParent) {
            $node->states = self::states($machine, $transitions, $state);
        }

        return $node;
    }

    /**
     * A signal is XState's event; the graph allows one transition per signal and state.
     *
     * @param  list<CompiledTransition>  $transitions
     */
    private static function events(CompiledMachine $machine, array $transitions, BackedEnum $state): stdClass
    {
        $events = new stdClass();

        foreach ($transitions as $transition) {
            if (! in_array($state, $transition->fromStates, true)) {
                continue;
            }

            $event = ['target' => self::target($machine, $state, $transition->targetState)];
            $guards = $transition->guardNames();

            if ($guards !== []) {
                $event['guard'] = implode(' and ', $guards);
            }

            if ($transition->signal !== $transition->name) {
                $event['meta']['transition'] = $transition->name;
            }

            if ($transition->trigger !== null) {
                $event['meta']['trigger'] = true;
            }

            if ($transition->systemOnly) {
                $event['meta']['systemOnly'] = true;
            }

            $events->{$transition->signal} = $event;
        }

        return $events;
    }

    /**
     * A sibling by its key, any other state by its `#id` path, which XState resolves from anywhere in the machine.
     */
    private static function target(CompiledMachine $machine, BackedEnum $fromState, BackedEnum $targetState): string
    {
        if ($machine->states->parentOf($fromState) === $machine->states->parentOf($targetState)) {
            return self::key($targetState);
        }

        $path = [self::key($targetState)];

        for ($parent = $machine->states->parentOf($targetState); $parent !== null; $parent = $machine->states->parentOf($parent)) {
            array_unshift($path, self::key($parent));
        }

        return '#' . self::id($machine) . '.' . implode('.', $path);
    }

    private static function key(BackedEnum $state): string
    {
        return (string) $state->value;
    }
}
