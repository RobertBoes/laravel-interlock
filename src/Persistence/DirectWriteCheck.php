<?php

namespace RobertBoes\Interlock\Persistence;

use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Events\BypassedGuardedAttributeWrite;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Exceptions\AmbiguousInitialStateException;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\ModelGraph;

/**
 * Runs right before the save writes, not from a model event, so muted model events can't skip it.
 */
final readonly class DirectWriteCheck
{
    /**
     * @param  Closure(BypassedWrite|BypassedGuardedAttributeWrite): void  $bypassed
     */
    public function __construct(private Model $model, private ModelGraph $graph, private Closure $bypassed) {}

    public function checkCreate(): void
    {
        $this->startStateAttributesInAnInitialState();
        $this->rejectGuardedAttributesOnCreate();
        $this->recordEnteringTheInitialStates();
    }

    /**
     * @param  array<string, StagedTransition>  $runningTransitions  keyed by state attribute
     */
    public function checkUpdate(array $runningTransitions): void
    {
        $this->rejectDirectWritesToStateAttributes($runningTransitions);
        $this->rejectDirectWritesToGuardedAttributes($runningTransitions);
        $this->recordEnteringBypassedStates($runningTransitions);
    }

    /**
     * A bypassed create may set the column itself, such as an import keeping a historical time.
     */
    private function recordEnteringTheInitialStates(): void
    {
        foreach ($this->graph->enteredAtColumns() as $column => $stateAttribute) {
            if (($this->model->getAttributes()[$column] ?? null) === null) {
                $this->model->setAttribute($column, $this->model->freshTimestamp());
            } else {
                $this->rejectOutsideBypass(DirectWriteException::createdWithEnteredAtColumn($this->model, $column, $stateAttribute));
            }
        }
    }

    /**
     * A running transition sets its own column. A bypassed write that changes the state sets it, unless it sets the
     * column itself.
     *
     * @param  array<string, StagedTransition>  $runningTransitions
     */
    private function recordEnteringBypassedStates(array $runningTransitions): void
    {
        foreach ($this->graph->enteredAtColumns() as $column => $stateAttribute) {
            if (isset($runningTransitions[$stateAttribute])) {
                continue;
            }

            if ($this->model->isDirty($column)) {
                $this->rejectOutsideBypass(DirectWriteException::toEnteredAtColumn($this->model, $column, $stateAttribute));
            } elseif ($this->model->isDirty($stateAttribute)) {
                $this->model->setAttribute($column, $this->model->freshTimestamp());
            }
        }
    }

    private function startStateAttributesInAnInitialState(): void
    {
        foreach ($this->graph->machines as $machine) {
            $state = $this->model->getAttribute($machine->stateAttribute);

            if ($state === null) {
                $this->model->setAttribute($machine->stateAttribute, $this->onlyInitialState($machine));
            } elseif ($machine->states->isParent($state)) {
                throw DirectWriteException::createdInParentState($this->model, $machine, $state);
            } elseif (! $machine->isInitialState($state)) {
                $this->bypassOrReject(
                    DirectWriteException::notAnInitialState($this->model, $machine, $state),
                    fn(string $reason) => new BypassedWrite($reason, $this->model, $machine->stateAttribute, null, $state),
                );
            }
        }
    }

    private function onlyInitialState(CompiledMachine $machine): BackedEnum
    {
        return $machine->soleInitialState() ?? throw AmbiguousInitialStateException::noStateGiven($this->model, $machine);
    }

    /**
     * @param  array<string, StagedTransition>  $runningTransitions
     */
    private function rejectDirectWritesToStateAttributes(array $runningTransitions): void
    {
        foreach ($this->graph->machines as $machine) {
            $stateAttribute = $machine->stateAttribute;

            if (! $this->model->isDirty($stateAttribute)) {
                continue;
            }

            $newState = $this->model->getAttribute($stateAttribute);

            if ($newState !== null && $machine->states->isParent($newState)) {
                throw DirectWriteException::toParentState($this->model, $machine, $newState);
            }

            if ($this->runningTransitionWrote($runningTransitions[$stateAttribute] ?? null, $stateAttribute)) {
                continue;
            }

            $oldState = $this->model->getOriginal($stateAttribute);

            $this->bypassOrReject(
                DirectWriteException::toStateAttribute($this->model, $stateAttribute, $oldState, $newState),
                fn(string $reason) => new BypassedWrite($reason, $this->model, $stateAttribute, $oldState, $newState),
            );
        }
    }

    /**
     * @param  array<string, StagedTransition>  $runningTransitions
     */
    private function rejectDirectWritesToGuardedAttributes(array $runningTransitions): void
    {
        $running = array_values(array_map(fn(StagedTransition $staged) => $staged->compiled->name, $runningTransitions));

        foreach ($this->graph->guardedAttributes as $guardedAttribute => $writers) {
            if (! $this->model->isDirty($guardedAttribute) || array_intersect($running, $writers) !== []) {
                continue;
            }

            $this->bypassGuardedAttributeOrReject($guardedAttribute, DirectWriteException::toGuardedAttribute($this->model, $guardedAttribute, $writers, $running));
        }
    }

    private function rejectGuardedAttributesOnCreate(): void
    {
        foreach ($this->graph->guardedAttributes as $guardedAttribute => $writers) {
            if (($this->model->getAttributes()[$guardedAttribute] ?? null) !== null) {
                $this->bypassGuardedAttributeOrReject($guardedAttribute, DirectWriteException::createdWithGuardedAttribute($this->model, $guardedAttribute, $writers));
            }
        }
    }

    private function bypassGuardedAttributeOrReject(string $guardedAttribute, DirectWriteException $rejection): void
    {
        $this->bypassOrReject($rejection, fn(string $reason) => new BypassedGuardedAttributeWrite(
            $reason,
            $this->model,
            $guardedAttribute,
            $this->model->getOriginal($guardedAttribute),
            $this->model->getAttribute($guardedAttribute),
        ));
    }

    /**
     * The bypassed write is dispatched and recorded only once the save succeeds, and its event waits for the commit.
     *
     * @param  Closure(string): (BypassedWrite|BypassedGuardedAttributeWrite)  $bypassedWrite
     */
    private function bypassOrReject(DirectWriteException $rejection, Closure $bypassedWrite): void
    {
        $reason = Container::getInstance()->make(BypassScope::class)->reason() ?? throw $rejection;

        ($this->bypassed)($bypassedWrite($reason));
    }

    private function rejectOutsideBypass(DirectWriteException $rejection): void
    {
        if (Container::getInstance()->make(BypassScope::class)->reason() === null) {
            throw $rejection;
        }
    }

    private function runningTransitionWrote(?StagedTransition $running, string $stateAttribute): bool
    {
        return $running !== null && $this->model->getAttribute($stateAttribute) === $running->compiled->targetState;
    }
}
