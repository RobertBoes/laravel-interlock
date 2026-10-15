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
 * The direct writes a model's own save makes to its state attributes and guarded attributes (ADR 0001), checked right
 * before the save writes, whether or not model events are muted (ADR 0010). Each one is rejected, unless a bypass is
 * active and it is kept as a bypassed write.
 */
final readonly class DirectWriteCheck
{
    /**
     * @param  Closure(BypassedWrite|BypassedGuardedAttributeWrite): void  $bypassed  called with each direct write a bypass lets through
     */
    public function __construct(private Model $model, private ModelGraph $graph, private Closure $bypassed) {}

    /**
     * Starts each state attribute left unset in its machine's initial state, and checks the model is created in an
     * initial state and without guarded attributes.
     */
    public function checkCreate(): void
    {
        $this->startStateAttributesInAnInitialState();
        $this->rejectGuardedAttributesOnCreate();
    }

    /**
     * @param  array<string, StagedTransition>  $runningTransitions  keyed by state attribute, being written by the save
     */
    public function checkUpdate(array $runningTransitions): void
    {
        $this->rejectDirectWritesToStateAttributes($runningTransitions);
        $this->rejectDirectWritesToGuardedAttributes($runningTransitions);
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
     * A guarded attribute may only change in a transition allowed to write it.
     *
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
     * Keeps the direct write as a bypassed write, dispatched and recorded once it is saved; throws the rejection when no
     * bypass is active.
     *
     * @param  Closure(string): (BypassedWrite|BypassedGuardedAttributeWrite)  $bypassedWrite  builds the bypassed write from the bypass reason
     */
    private function bypassOrReject(DirectWriteException $rejection, Closure $bypassedWrite): void
    {
        $reason = Container::getInstance()->make(BypassScope::class)->reason() ?? throw $rejection;

        ($this->bypassed)($bypassedWrite($reason));
    }

    private function runningTransitionWrote(?StagedTransition $running, string $stateAttribute): bool
    {
        return $running !== null && $this->model->getAttribute($stateAttribute) === $running->compiled->targetState;
    }
}
