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
        return array_merge(...array_map($this->problemsOfModel(...), $models));
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    private function problemsOfModel(string $model): array
    {
        try {
            $graph = $this->graphs->for(new $model());
        } catch (InvalidDefinitionException $exception) {
            return [$exception->getMessage()];
        }

        return array_merge(...array_map(
            fn(CompiledMachine $machine) => $this->problemsOf($model, $machine, $graph->transitionsOf($machine)),
            array_values($graph->machines),
        ));
    }

    /**
     * Each leaf state's problems in turn, then each final state's.
     *
     * @param  class-string<Model>  $model
     * @param  list<CompiledTransition>  $transitions
     * @return list<string>
     */
    private function problemsOf(string $model, CompiledMachine $machine, array $transitions): array
    {
        $reachable = $this->reachableStates($machine, $transitions);

        $leafStateProblems = array_map(fn(BackedEnum $state) => [
            ...$this->unreachable($machine, $state, $reachable),
            ...$this->withNoWayOut($machine, $state, $transitions),
        ], $machine->states->leaves());
        $finalStateProblems = array_map(fn(BackedEnum $finalState) => $this->leftFinalState($machine, $finalState, $transitions), $machine->finalStates);

        $prefix = sprintf('%s on %s [%s]: ', $machine->class, $model, $machine->stateAttribute);

        return array_map(fn(string $problem) => $prefix . $problem, array_merge(...$leafStateProblems, ...$finalStateProblems));
    }

    /**
     * @param  list<BackedEnum>  $reachable
     * @return list<string>
     */
    private function unreachable(CompiledMachine $machine, BackedEnum $state, array $reachable): array
    {
        if (in_array($state, $reachable, true)) {
            return [];
        }

        return [sprintf(
            'state [%s] is unreachable. No transitions lead to it from the initial states [%s].',
            $state->value,
            StateValues::listed($machine->initialStates),
        )];
    }

    /**
     * @param  list<CompiledTransition>  $transitions
     * @return list<string>
     */
    private function withNoWayOut(CompiledMachine $machine, BackedEnum $state, array $transitions): array
    {
        if ($machine->isFinalState($state) || $this->transitionsLeaving($machine, $state, $transitions) !== []) {
            return [];
        }

        return [sprintf(
            'state [%s] has no way out. No transition starts from it, and it is not a final state, so add a transition from it or declare it in #[FinalStates].',
            $state->value,
        )];
    }

    /**
     * @param  list<CompiledTransition>  $transitions
     * @return list<string>
     */
    private function leftFinalState(CompiledMachine $machine, BackedEnum $finalState, array $transitions): array
    {
        return array_map(fn(CompiledTransition $transition) => sprintf(
            'final state [%s] has outgoing transition [%s]. A final state is never left, so remove [%s] or the state from #[FinalStates].',
            $finalState->value,
            $transition->name,
            $transition->name,
        ), $this->transitionsLeaving($machine, $finalState, $transitions));
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
