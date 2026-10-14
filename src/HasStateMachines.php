<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Exceptions\AmbiguousInitialStateException;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Graph\ModelGraph;

/**
 * @mixin Model
 */
trait HasStateMachines
{
    private ?CompiledTransition $interlockRunningTransition = null;

    public function transition(string $name): static
    {
        $transition = $this->interlockGraph()->transition($name);
        $fromState = $this->getOriginal($transition->stateAttribute);

        if (! $transition->startsFrom($fromState)) {
            throw IllegalTransitionException::currentStateNotAFromState($this, $transition, $fromState);
        }

        $unsavedState = $this->getAttribute($transition->stateAttribute);
        $this->setAttribute($transition->stateAttribute, $transition->targetState);

        $this->interlockRunningTransition = $transition;

        try {
            $saved = $this->save();
        } finally {
            $this->interlockRunningTransition = null;
        }

        if (! $saved) {
            $this->setAttribute($transition->stateAttribute, $unsavedState);

            return $this;
        }

        Event::dispatch(new Transitioned($this, $transition->name, $fromState, $transition->targetState));

        return $this;
    }

    /**
     * Called by performUpdate() after the updating event, whether or not model events are muted (ADR 0010).
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        $this->rejectDirectWritesToStateAttributes();

        return parent::getDirtyForUpdate();
    }

    /**
     * Called by performInsert() after the creating event, whether or not model events are muted (ADR 0010).
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert(): array
    {
        $this->startStateAttributesInAnInitialState();

        return parent::getAttributesForInsert();
    }

    private function startStateAttributesInAnInitialState(): void
    {
        foreach ($this->interlockGraph()->machines as $machine) {
            $state = $this->getAttribute($machine->stateAttribute);

            if ($state === null) {
                $this->setAttribute($machine->stateAttribute, $this->onlyInitialState($machine));
            } elseif (! $machine->isInitialState($state)) {
                throw DirectWriteException::notAnInitialState($this, $machine, $state);
            }
        }
    }

    private function onlyInitialState(CompiledMachine $machine): BackedEnum
    {
        if (count($machine->initialStates) > 1) {
            throw AmbiguousInitialStateException::noStateGiven($this, $machine);
        }

        return $machine->initialStates[0];
    }

    private function rejectDirectWritesToStateAttributes(): void
    {
        foreach ($this->interlockGraph()->machines as $machine) {
            $stateAttribute = $machine->stateAttribute;

            if (! $this->isDirty($stateAttribute) || $this->runningTransitionWrote($stateAttribute)) {
                continue;
            }

            throw DirectWriteException::toStateAttribute($this, $stateAttribute, $this->getOriginal($stateAttribute), $this->getAttribute($stateAttribute));
        }
    }

    private function runningTransitionWrote(string $stateAttribute): bool
    {
        return $this->interlockRunningTransition?->stateAttribute === $stateAttribute
            && $this->getAttribute($stateAttribute) === $this->interlockRunningTransition->targetState;
    }

    private function interlockGraph(): ModelGraph
    {
        return Container::getInstance()->make(GraphRepository::class)->for($this);
    }
}
