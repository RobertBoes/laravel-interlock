<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Support\StateValues;

/**
 * Finds the common mistakes in a model's state machines: states that cannot be reached, states with no way out, final states that can be left, and definitions the graph rejects.
 */
final readonly class SoundnessCheck
{
    public function __construct(private GraphRepository $graphs) {}

    /**
     * @param  list<class-string<Model>>  $models
     * @return list<string>
     */
    public function problems(array $models): array
    {
        $problems = [];

        foreach ($models as $model) {
            try {
                $graph = $this->graphs->for(new $model());
            } catch (InvalidDefinitionException $exception) {
                $problems[] = $exception->getMessage();

                continue;
            }

            foreach ($graph->machines as $machine) {
                $problems = [...$problems, ...$this->problemsOf($model, $machine, $graph->transitionsOf($machine))];
            }
        }

        return $problems;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<CompiledTransition>  $transitions
     * @return list<string>
     */
    private function problemsOf(string $model, CompiledMachine $machine, array $transitions): array
    {
        $prefix = sprintf('%s on %s [%s]: ', $machine->class, $model, $machine->stateAttribute);
        $reachable = $this->reachableStates($machine, $transitions);
        $problems = [];

        foreach ($machine->states->leaves() as $state) {
            if (! in_array($state, $reachable, true)) {
                $problems[] = $prefix . sprintf(
                    'state [%s] is unreachable. No transitions lead to it from the initial states [%s].',
                    $state->value,
                    StateValues::listed($machine->initialStates),
                );
            }

            if (! $machine->isFinalState($state) && $this->transitionsLeaving($machine, $state, $transitions) === []) {
                $problems[] = $prefix . sprintf(
                    'state [%s] has no way out. No transition starts from it, and it is not a final state, so add a transition from it or declare it in #[FinalStates].',
                    $state->value,
                );
            }
        }

        foreach ($machine->finalStates as $finalState) {
            foreach ($this->transitionsLeaving($machine, $finalState, $transitions) as $transition) {
                $problems[] = $prefix . sprintf(
                    'final state [%s] has outgoing transition [%s]. A final state is never left, so remove [%s] or the state from #[FinalStates].',
                    $finalState->value,
                    $transition->name,
                    $transition->name,
                );
            }
        }

        return $problems;
    }

    /**
     * @param  list<CompiledTransition>  $transitions
     * @return list<BackedEnum>
     */
    private function reachableStates(CompiledMachine $machine, array $transitions): array
    {
        $reachable = $machine->initialStates;

        for ($index = 0; $index < count($reachable); $index++) {
            foreach ($transitions as $transition) {
                if ($transition->startsFrom($reachable[$index]) && ! in_array($transition->targetState, $reachable, true)) {
                    $reachable[] = $transition->targetState;
                }
            }
        }

        return $reachable;
    }

    /**
     * The transitions that start from the state, or from any state nested within it.
     *
     * @param  list<CompiledTransition>  $transitions
     * @return list<CompiledTransition>
     */
    private function transitionsLeaving(CompiledMachine $machine, BackedEnum $state, array $transitions): array
    {
        $leaves = $machine->states->leavesWithin($state);

        return array_values(array_filter(
            $transitions,
            fn(CompiledTransition $transition) => array_any($leaves, $transition->startsFrom(...)),
        ));
    }
}
