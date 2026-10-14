<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\AmbiguousInitialStateException;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Graph\ModelGraph;
use RobertBoes\Interlock\Graph\TransitionSteps;
use RobertBoes\Interlock\Persistence\CompareAndSwapBuilder;
use RobertBoes\Interlock\Testing\TransitionLog;
use Throwable;

/**
 * @mixin Model
 */
trait HasStateMachines
{
    private ?CompiledTransition $interlockRunningTransition = null;

    private ?BackedEnum $interlockLoadedState = null;

    /** @var list<BypassedWrite> dispatched once the save that writes them finishes */
    private array $interlockBypassedWrites = [];

    /**
     * Apply a transition by name, `transition('publish')`, or as an instance of its class, `transition(new Ship($trackingCode))`.
     *
     * The actor is the authenticated user, unless one is given: `transition('approve', $manager)`. Pass null to apply
     * it on behalf of no one, as a trigger does.
     */
    public function transition(string|Transition $transition, ?Authenticatable $actor = null): static
    {
        $graph = $this->interlockGraph();
        $compiled = is_string($transition) ? $graph->transition($transition) : $graph->transitionOfClass($transition);
        $fromState = $this->getOriginal($compiled->stateAttribute);

        if (! $compiled->startsFrom($fromState)) {
            throw IllegalTransitionException::currentStateNotAFromState($this, $compiled, $fromState);
        }

        $log = Container::getInstance()->make(TransitionLog::class);
        // Built while faked too, so a transition that cannot be built from its name still throws.
        $steps = $compiled->steps($transition instanceof Transition ? $transition : null);
        $steps = $log->faking() ? TransitionSteps::none() : $steps;
        $steps->authorise($this, func_num_args() > 1 ? $actor : $this->authenticatedActor(), $compiled);

        $unsavedAttributes = $this->getAttributes();

        try {
            $committed = $this->writeTransition($compiled, $steps, $fromState);
        } catch (Throwable $exception) {
            $this->setRawAttributes($unsavedAttributes);

            throw $exception;
        }

        if (! $committed) {
            $this->setRawAttributes($unsavedAttributes);

            return $this;
        }

        $this->getConnection()->afterCommit(function () use ($compiled, $steps, $fromState, $log) {
            $log->record($this, $compiled->name);
            $steps->afterCommit($this);

            Event::dispatch(new Transitioned($this, $compiled->name, $fromState, $compiled->targetState));
        });

        return $this;
    }

    /**
     * The copy starts its lifecycle over: its state attributes are left out of the copy and its guarded attributes
     * cleared, so a replicating listener may set them, and each state attribute it leaves unset starts in its
     * machine's initial state, or stays null when the machine has several.
     *
     * @param  list<string>|null  $except
     */
    public function replicate(?array $except = null): static
    {
        $graph = $this->interlockGraph();
        $copy = parent::replicate([...$except ?? [], ...array_keys($graph->machines), ...array_keys($graph->guardedAttributes)]);

        foreach ($graph->machines as $machine) {
            if (($copy->getAttributes()[$machine->stateAttribute] ?? null) === null) {
                $copy->setAttribute($machine->stateAttribute, count($machine->initialStates) === 1 ? $machine->initialStates[0] : null);
            }
        }

        foreach (array_keys($graph->guardedAttributes) as $guardedAttribute) {
            if (! array_key_exists($guardedAttribute, $copy->getAttributes())) {
                $copy->setAttribute($guardedAttribute, null);
            }
        }

        return $copy;
    }

    /**
     * Checks the guards, then runs the apply step and the compare-and-swap save, in one transaction that rolls back when
     * a guard fails or the save fails or is cancelled.
     */
    private function writeTransition(CompiledTransition $transition, TransitionSteps $steps, BackedEnum $fromState): bool
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();

        $this->interlockRunningTransition = $transition;
        $this->interlockLoadedState = $fromState;

        try {
            $steps->checkGuards($this, $transition);
            $this->setAttribute($transition->stateAttribute, $transition->targetState);
            $steps->apply($this);

            Event::dispatch(new Transitioning($this, $transition->name, $fromState, $transition->targetState));

            $saved = $this->save();
            $saved ? $connection->commit() : $connection->rollBack();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        } finally {
            $this->interlockRunningTransition = null;
            $this->interlockLoadedState = null;
        }

        return $saved;
    }

    /**
     * A running transition always writes its state attribute, so a self-transition still
     * reaches the compare-and-swap update instead of being skipped as a clean save.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = parent::getDirty();

        if ($this->interlockRunningTransition !== null) {
            $stateAttribute = $this->interlockRunningTransition->stateAttribute;
            $dirty[$stateAttribute] ??= $this->getAttributes()[$stateAttribute];
        }

        return $dirty;
    }

    /**
     * A running transition is saved through a compare-and-swap query (ADR 0004, ADR 0010).
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->interlockRunningTransition === null || $this->interlockLoadedState === null) {
            return parent::performUpdate($query);
        }

        return parent::performUpdate(
            CompareAndSwapBuilder::replacing($query, $this->interlockRunningTransition, $this->interlockLoadedState),
        );
    }

    /**
     * Called by performUpdate() after the updating event, whether or not model events are muted (ADR 0010).
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        $this->interlockBypassedWrites = [];
        $this->rejectDirectWritesToStateAttributes();
        $this->rejectDirectWritesToGuardedAttributes();

        return parent::getDirtyForUpdate();
    }

    /**
     * Called by performInsert() after the creating event, whether or not model events are muted (ADR 0010).
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert(): array
    {
        $this->interlockBypassedWrites = [];
        $this->startStateAttributesInAnInitialState();
        $this->rejectGuardedAttributesOnCreate();

        return parent::getAttributesForInsert();
    }

    /**
     * Called by save() once the write succeeded, whether or not model events are muted.
     *
     * @param  array<string, mixed>  $options
     */
    protected function finishSave(array $options): void
    {
        $bypassedWrites = $this->interlockBypassedWrites;
        $this->interlockBypassedWrites = [];

        parent::finishSave($options);

        foreach ($bypassedWrites as $bypassedWrite) {
            Event::dispatch($bypassedWrite);
        }
    }

    private function startStateAttributesInAnInitialState(): void
    {
        foreach ($this->interlockGraph()->machines as $machine) {
            $state = $this->getAttribute($machine->stateAttribute);

            if ($state === null) {
                $this->setAttribute($machine->stateAttribute, $this->onlyInitialState($machine));
            } elseif ($machine->states->isParent($state)) {
                throw DirectWriteException::createdInParentState($this, $machine, $state);
            } elseif (! $machine->isInitialState($state)) {
                $this->bypassOrReject($machine->stateAttribute, null, $state, DirectWriteException::notAnInitialState($this, $machine, $state));
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

            if (! $this->isDirty($stateAttribute)) {
                continue;
            }

            $newState = $this->getAttribute($stateAttribute);

            if ($newState !== null && $machine->states->isParent($newState)) {
                throw DirectWriteException::toParentState($this, $machine, $newState);
            }

            if ($this->runningTransitionWrote($stateAttribute)) {
                continue;
            }

            $oldState = $this->getOriginal($stateAttribute);

            $this->bypassOrReject($stateAttribute, $oldState, $newState, DirectWriteException::toStateAttribute($this, $stateAttribute, $oldState, $newState));
        }
    }

    /**
     * A guarded attribute may only change in a transition allowed to write it.
     */
    private function rejectDirectWritesToGuardedAttributes(): void
    {
        foreach ($this->interlockGraph()->guardedAttributes as $guardedAttribute => $writers) {
            if (! $this->isDirty($guardedAttribute) || in_array($this->interlockRunningTransition?->name, $writers, true)) {
                continue;
            }

            $this->bypassReason(DirectWriteException::toGuardedAttribute($this, $guardedAttribute, $writers, $this->interlockRunningTransition));
        }
    }

    private function rejectGuardedAttributesOnCreate(): void
    {
        foreach ($this->interlockGraph()->guardedAttributes as $guardedAttribute => $writers) {
            if (($this->getAttributes()[$guardedAttribute] ?? null) !== null) {
                $this->bypassReason(DirectWriteException::createdWithGuardedAttribute($this, $guardedAttribute, $writers));
            }
        }
    }

    private function bypassOrReject(string $stateAttribute, ?BackedEnum $oldState, ?BackedEnum $newState, DirectWriteException $rejection): void
    {
        $this->interlockBypassedWrites[] = new BypassedWrite($this->bypassReason($rejection), $this, $stateAttribute, $oldState, $newState);
    }

    /**
     * Why the direct write is allowed after all; throws the rejection when no bypass is active.
     */
    private function bypassReason(DirectWriteException $rejection): string
    {
        return Container::getInstance()->make(BypassScope::class)->reason() ?? throw $rejection;
    }

    private function runningTransitionWrote(string $stateAttribute): bool
    {
        return $this->interlockRunningTransition?->stateAttribute === $stateAttribute
            && $this->getAttribute($stateAttribute) === $this->interlockRunningTransition->targetState;
    }

    private function authenticatedActor(): ?Authenticatable
    {
        return Container::getInstance()->make(AuthFactory::class)->guard()->user();
    }

    private function interlockGraph(): ModelGraph
    {
        return Container::getInstance()->make(GraphRepository::class)->for($this);
    }
}
