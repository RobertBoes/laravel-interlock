<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Events\BypassedGuardedAttributeWrite;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\AmbiguousInitialStateException;
use RobertBoes\Interlock\Exceptions\CancelledTransitionException;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\MissingBuilderTraitException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Graph\ModelGraph;
use RobertBoes\Interlock\Graph\TransitionSteps;
use RobertBoes\Interlock\History\HistoryEntry;
use RobertBoes\Interlock\History\HistoryRecord;
use RobertBoes\Interlock\History\HistoryRecorder;
use RobertBoes\Interlock\Persistence\CompareAndSwapBuilder;
use RobertBoes\Interlock\Persistence\StagedTransition;
use RobertBoes\Interlock\Testing\TransitionLog;
use Throwable;

/**
 * @mixin Model
 */
trait HasStateMachines
{
    /** @var array<string, StagedTransition> keyed by state attribute, written by the next save */
    private array $interlockStagedTransitions = [];

    /** @var array<string, StagedTransition> keyed by state attribute, being written by the current save */
    private array $interlockRunningTransitions = [];

    /** Whether the compare-and-swap update of the running transitions ran, so a nested save writes as usual */
    private bool $interlockRunningTransitionsWritten = false;

    /** @var list<BypassedWrite|BypassedGuardedAttributeWrite> dispatched once the save that writes them finishes */
    private array $interlockBypassedWrites = [];

    /** @var array<class-string<Model>, true> the models whose builder was found to use RejectsDirectWrites */
    private static array $interlockCheckedBuilders = [];

    /**
     * Fails as soon as a model with a custom builder that lacks RejectsDirectWrites is used, every time it is used (ADR 0009).
     */
    public function initializeHasStateMachines(): void
    {
        if (isset(self::$interlockCheckedBuilders[static::class])) {
            return;
        }

        $builder = $this->newEloquentBuilder($this->newBaseQueryBuilder());

        if (! in_array(RejectsDirectWrites::class, class_uses_recursive($builder), true)) {
            throw MissingBuilderTraitException::on($this, $builder);
        }

        self::$interlockCheckedBuilders[static::class] = true;
    }

    /**
     * Apply a transition by name, `transition('publish')`, or as an instance of its class, `transition(new Ship($trackingCode))`.
     *
     * The actor is the authenticated user, unless one is given: `transition('approve', $manager)`. Pass null to apply
     * it on behalf of no one, as a trigger does. Transitions staged on the model's other machines are written with it.
     * When a saving or updating listener cancels the save, nothing is written and it throws.
     */
    public function transition(string|Transition $transition, ?Authenticatable $actor = null): static
    {
        $compiled = $this->interlockStageTransition($transition, func_num_args() > 1 ? $actor : $this->authenticatedActor(), null);
        $this->saveOrThrowWhenCancelled($compiled);

        return $this;
    }

    /**
     * Stage a transition for the next save() to write, so transitions on several machines and the model's other
     * changes go out in one update: `$order->stageTransition('ship')->stageTransition('capture_payment')->save()`.
     *
     * Only the graph is checked now, and the state attribute reads the target state straight away. Authorisation,
     * guards, the apply step and the compare-and-swap write all happen on save(), against the data as it will be
     * stored. A machine holds one staged transition at a time. A failing save writes nothing and drops every staged
     * transition, as do discardStagedTransitions() and refresh(). The actor defaults to the authenticated user.
     */
    public function stageTransition(string|Transition $transition, ?Authenticatable $actor = null): static
    {
        $this->interlockStageTransition($transition, func_num_args() > 1 ? $actor : $this->authenticatedActor(), null);

        return $this;
    }

    /**
     * @internal Applies the transition as its trigger does: on behalf of no one, recorded under the trigger's name.
     */
    public function interlockApplyTrigger(string $transition): void
    {
        $this->saveOrThrowWhenCancelled($this->interlockStageTransition($transition, null, $transition));
    }

    private function saveOrThrowWhenCancelled(CompiledTransition $transition): void
    {
        if (! $this->save()) {
            throw CancelledTransitionException::saveCancelled($this, $transition);
        }
    }

    private function interlockStageTransition(string|Transition $transition, ?Authenticatable $actor, ?string $trigger): CompiledTransition
    {
        $graph = $this->interlockGraph();
        $compiled = is_string($transition) ? $graph->transition($transition) : $graph->transitionOfClass($transition);
        $fromState = $this->getOriginal($compiled->stateAttribute);

        if (! $compiled->startsFrom($fromState)) {
            throw IllegalTransitionException::currentStateNotAFromState($this, $compiled, $fromState);
        }

        $alreadyStaged = $this->interlockStagedTransitions[$compiled->stateAttribute] ?? null;

        if ($alreadyStaged !== null) {
            throw IllegalTransitionException::anotherTransitionStaged($this, $compiled, $alreadyStaged->transition);
        }

        // Built while faked too, so a transition that cannot be built from its name still throws.
        $steps = $compiled->steps($transition instanceof Transition ? $transition : null);
        $steps = Container::getInstance()->make(TransitionLog::class)->faking() ? TransitionSteps::none() : $steps;

        $this->interlockStagedTransitions[$compiled->stateAttribute] = new StagedTransition($compiled, $steps, $fromState, $actor, $trigger);
        $this->setAttribute($compiled->stateAttribute, $compiled->targetState);

        return $compiled;
    }

    /**
     * Drop the staged transitions without saving, putting each state attribute back to the state it was staged from.
     */
    public function discardStagedTransitions(): static
    {
        foreach ($this->interlockStagedTransitions as $stateAttribute => $staged) {
            $this->setAttribute($stateAttribute, $staged->fromState);
        }

        $this->interlockStagedTransitions = [];

        return $this;
    }

    /**
     * Writes the staged transitions, if any, together with the model's other changes.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->interlockStagedTransitions === []) {
            return parent::save($options);
        }

        $staged = $this->interlockStagedTransitions;
        $this->interlockStagedTransitions = [];
        $unsaved = [$this->getAttributes(), $this->getRawOriginal(), $this->getChanges(), $this->wasRecentlyCreated];

        try {
            $committed = $this->writeTransitions($staged, $options);
        } catch (Throwable $exception) {
            $this->restoreUnsavedModel($unsaved, $staged);

            throw $exception;
        }

        if (! $committed) {
            $this->restoreUnsavedModel($unsaved, $staged);

            return false;
        }

        $this->getConnection()->afterCommit(function () use ($staged) {
            $log = Container::getInstance()->make(TransitionLog::class);

            foreach ($staged as $transition) {
                $log->record($this, $transition->transition->name);
                $transition->steps->afterCommit($this);

                Event::dispatch(new Transitioned($this, $transition->transition->name, $transition->fromState, $transition->transition->targetState));
            }
        });

        return true;
    }

    /**
     * Reloads the model's attributes from the database and drops its staged transitions.
     *
     * @return $this
     */
    public function refresh(): static
    {
        parent::refresh();

        $this->interlockStagedTransitions = [];

        return $this;
    }

    /**
     * The transitions the actor can apply to the model right now, across all its machines: those starting from its
     * current state whose authorisation allows the actor and whose guards hold, unsaved changes included. A transition
     * that takes input is offered on its authorisation alone; its guards run once it is applied with its input.
     * Nothing is written and no events are dispatched.
     *
     * The actor is the authenticated user, unless one is given; pass null to ask on behalf of no one. Each transition
     * serialises as its name and target state, ready for an API resource or Inertia props.
     *
     * @return Collection<int, AvailableTransition>
     */
    public function availableTransitions(?Authenticatable $actor = null): Collection
    {
        $actor = func_num_args() > 0 ? $actor : $this->authenticatedActor();
        $graph = $this->interlockGraph();
        $available = [];

        foreach ($graph->machines as $machine) {
            foreach ($graph->transitionsOf($machine) as $transition) {
                if ($transition->isAvailable($this, $actor)) {
                    $available[] = new AvailableTransition($transition->name, $transition->targetState);
                }
            }
        }

        return new Collection($available);
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
     * The model's history: every transition and bypassed write on its machines that record history, or on one of them
     * when a state attribute is given. Unordered; entries recorded in the same second share a timestamp, so order a
     * timeline by key: `$order->history('status')->oldest('id')->get()`.
     *
     * @return MorphMany<HistoryEntry, $this>
     */
    public function history(?string $stateAttribute = null): MorphMany
    {
        $history = $this->morphMany(HistoryEntry::class, 'model');

        return $stateAttribute === null ? $history : $history->where('state_attribute', $stateAttribute);
    }

    /**
     * Authorises every actor, then checks each transition's guards and runs its apply step, and saves them all through
     * one compare-and-swap update and records their history, in one transaction that rolls back when a guard fails or
     * the save fails or is cancelled. Guards and authorisation see the state each transition starts from, plus the changes of the
     * transitions staged before it.
     *
     * @param  array<string, StagedTransition>  $staged
     * @param  array<string, mixed>  $options
     */
    private function writeTransitions(array $staged, array $options): bool
    {
        foreach ($staged as $stateAttribute => $transition) {
            if ($this->getAttribute($stateAttribute) !== $transition->transition->targetState) {
                throw DirectWriteException::toStagedStateAttribute($this, $transition->transition, $this->getAttribute($stateAttribute));
            }

            $this->setAttribute($stateAttribute, $transition->fromState);
        }

        foreach ($staged as $transition) {
            $transition->steps->authorise($this, $transition->actor, $transition->transition);
        }

        $connection = $this->getConnection();
        $connection->beginTransaction();

        $this->interlockRunningTransitions = $staged;
        $written = [];
        $oldAttributes = [];

        try {
            foreach ($staged as $stateAttribute => $transition) {
                $compiled = $transition->transition;

                $transition->steps->checkGuards($this, $compiled);
                $this->setAttribute($compiled->stateAttribute, $compiled->targetState);
                $attributesBeforeApply = $this->getAttributes();
                $transition->steps->apply($this);
                $written[$stateAttribute] = $this->attributesWrittenSince($attributesBeforeApply, $compiled->stateAttribute);
                $oldAttributes[$stateAttribute] = array_map(fn(string $attribute) => $this->getRawOriginal($attribute), $written[$stateAttribute]);

                Event::dispatch(new Transitioning($this, $compiled->name, $transition->fromState, $compiled->targetState));
            }

            $saved = parent::save($options);

            if ($saved) {
                foreach ($staged as $stateAttribute => $transition) {
                    $this->recordHistory(new HistoryRecord(
                        $this,
                        $stateAttribute,
                        $transition->transition->name,
                        $transition->fromState,
                        $transition->transition->targetState,
                        $transition->actor,
                        $oldAttributes[$stateAttribute],
                        array_map(fn(string $attribute) => $this->getAttributes()[$attribute] ?? null, $written[$stateAttribute]),
                        trigger: $transition->trigger,
                    ));
                }
            }

            $saved ? $connection->commit() : $connection->rollBack();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        } finally {
            $this->interlockRunningTransitions = [];
            $this->interlockRunningTransitionsWritten = false;
        }

        return $saved;
    }

    /**
     * The transition's own writes: its state attribute and the attributes its apply step set, keyed by themselves.
     *
     * @param  array<string, mixed>  $attributesBeforeApply
     * @return array<string, string>
     */
    private function attributesWrittenSince(array $attributesBeforeApply, string $stateAttribute): array
    {
        $written = [$stateAttribute];

        foreach ($this->getAttributes() as $attribute => $value) {
            if (! array_key_exists($attribute, $attributesBeforeApply) || $attributesBeforeApply[$attribute] !== $value) {
                $written[] = $attribute;
            }
        }

        return array_combine($written, $written);
    }

    /**
     * Puts the model back as it was before a failed save, without the transitions it tried to write. The save may
     * have synced the original attributes before it failed, while the database rolled back, so they are put back too.
     *
     * @param  array{array<string, mixed>, array<string, mixed>, array<string, mixed>, bool}  $unsaved  the attributes, original attributes, changes and whether the model was recently created
     * @param  array<string, StagedTransition>  $staged
     */
    private function restoreUnsavedModel(array $unsaved, array $staged): void
    {
        [$attributes, $original, $changes, $wasRecentlyCreated] = $unsaved;

        $this->setRawAttributes($attributes);
        $this->original = $original;
        $this->changes = $changes;
        $this->wasRecentlyCreated = $wasRecentlyCreated;

        foreach ($staged as $stateAttribute => $transition) {
            $this->setAttribute($stateAttribute, $transition->fromState);
        }
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

        if ($this->interlockRunningTransitionsWritten) {
            return $dirty;
        }

        foreach (array_keys($this->interlockRunningTransitions) as $stateAttribute) {
            $dirty[$stateAttribute] ??= $this->getAttributes()[$stateAttribute];
        }

        return $dirty;
    }

    /**
     * Running transitions are saved through a compare-and-swap query (ADR 0004, ADR 0010). Once it ran, a save nested
     * in the transition, from an observer or an apply step, writes through the plain query: the row is in the target
     * state already, and the transaction holds it.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        $query = $this->interlockRunningTransitions === [] || $this->interlockRunningTransitionsWritten
            ? $this->interlockModelSaveQuery($query)
            : CompareAndSwapBuilder::replacing($query, $this->interlockRunningTransitions, function () {
                $this->interlockRunningTransitionsWritten = true;
            });

        return $this->writeRecordingBypassedWrites(fn() => parent::performUpdate($query));
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        $query = $this->interlockModelSaveQuery($query);

        return $this->writeRecordingBypassedWrites(fn() => parent::performInsert($query));
    }

    /**
     * Records the history of the write's bypassed writes, in one transaction with the write: one entry for each machine
     * whose state attribute, or one of whose guarded attributes, the write bypassed. A running transition's write is
     * in the transition's transaction already.
     *
     * @param  Closure(): bool  $write
     */
    private function writeRecordingBypassedWrites(Closure $write): bool
    {
        $writeAndRecord = function () use ($write): bool {
            if (! $write()) {
                return false;
            }

            foreach ($this->bypassedWritesByStateAttribute() as $stateAttribute => [$reason, $attributes]) {
                $this->recordHistory(new HistoryRecord(
                    $this,
                    $stateAttribute,
                    null,
                    $this->getOriginal($stateAttribute),
                    $this->getAttribute($stateAttribute),
                    $this->authenticatedActor(),
                    array_map(fn(string $attribute) => $this->getRawOriginal($attribute), $attributes),
                    array_map(fn(string $attribute) => $this->getAttributes()[$attribute] ?? null, $attributes),
                    $reason,
                ));
            }

            return true;
        };

        return $this->interlockRunningTransitions === [] && $this->mayMakeABypassedWriteThatRecordsHistory()
            ? $this->getConnection()->transaction($writeAndRecord)
            : $writeAndRecord();
    }

    /**
     * The bypassed writes grouped by the machine they belong to: a state attribute's own, or the machines declaring a
     * guarded attribute.
     *
     * @return array<string, array{string, array<string, string>}> the reason and the attributes written, keyed by state attribute
     */
    private function bypassedWritesByStateAttribute(): array
    {
        $graph = $this->interlockGraph();
        $grouped = [];

        foreach ($this->interlockBypassedWrites as $bypassedWrite) {
            [$attribute, $stateAttributes] = $bypassedWrite instanceof BypassedWrite
                ? [$bypassedWrite->stateAttribute, [$bypassedWrite->stateAttribute]]
                : [$bypassedWrite->attribute, $graph->guardedAttributeMachines[$bypassedWrite->attribute] ?? []];

            foreach ($stateAttributes as $stateAttribute) {
                $grouped[$stateAttribute] = [$bypassedWrite->reason, [...$grouped[$stateAttribute][1] ?? [], $attribute => $attribute]];
            }
        }

        return $grouped;
    }

    /**
     * Whether the write may be a bypassed write to record: a bypass is active and one of the model's machines records
     * history. Not what is dirty now: this is decided before the updating or creating event, whose listeners may still
     * set a state or guarded attribute.
     */
    private function mayMakeABypassedWriteThatRecordsHistory(): bool
    {
        foreach ($this->interlockGraph()->machines as $machine) {
            if ($machine->recordsHistory) {
                return Container::getInstance()->make(BypassScope::class)->reason() !== null;
            }
        }

        return false;
    }

    private function recordHistory(HistoryRecord $record): void
    {
        if ($this->interlockGraph()->machines[$record->stateAttribute]->recordsHistory) {
            Container::getInstance()->make(HistoryRecorder::class)->record($record);
        }
    }

    /**
     * Interlocked models get Interlock's builder unless they set a custom builder, which must use RejectsDirectWrites (ADR 0009).
     *
     * @param  QueryBuilder  $query
     * @return Builder<*>
     */
    public function newEloquentBuilder($query)
    {
        $builder = parent::newEloquentBuilder($query);

        return $builder::class === Builder::class ? new InterlockBuilder($query) : $builder;
    }

    /**
     * The model's save checks its own direct writes, so its builder lets the write through.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    private function interlockModelSaveQuery(Builder $query): Builder
    {
        return method_exists($query, 'interlockSavesModel') ? $query->interlockSavesModel() : $query;
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
                $this->bypassOrReject(
                    DirectWriteException::notAnInitialState($this, $machine, $state),
                    fn(string $reason) => new BypassedWrite($reason, $this, $machine->stateAttribute, null, $state),
                );
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

            $this->bypassOrReject(
                DirectWriteException::toStateAttribute($this, $stateAttribute, $oldState, $newState),
                fn(string $reason) => new BypassedWrite($reason, $this, $stateAttribute, $oldState, $newState),
            );
        }
    }

    /**
     * A guarded attribute may only change in a transition allowed to write it.
     */
    private function rejectDirectWritesToGuardedAttributes(): void
    {
        $running = array_values(array_map(fn(StagedTransition $transition) => $transition->transition->name, $this->interlockRunningTransitions));

        foreach ($this->interlockGraph()->guardedAttributes as $guardedAttribute => $writers) {
            if (! $this->isDirty($guardedAttribute) || array_intersect($running, $writers) !== []) {
                continue;
            }

            $this->bypassGuardedAttributeOrReject($guardedAttribute, DirectWriteException::toGuardedAttribute($this, $guardedAttribute, $writers, $running));
        }
    }

    private function rejectGuardedAttributesOnCreate(): void
    {
        foreach ($this->interlockGraph()->guardedAttributes as $guardedAttribute => $writers) {
            if (($this->getAttributes()[$guardedAttribute] ?? null) !== null) {
                $this->bypassGuardedAttributeOrReject($guardedAttribute, DirectWriteException::createdWithGuardedAttribute($this, $guardedAttribute, $writers));
            }
        }
    }

    private function bypassGuardedAttributeOrReject(string $guardedAttribute, DirectWriteException $rejection): void
    {
        $this->bypassOrReject($rejection, fn(string $reason) => new BypassedGuardedAttributeWrite(
            $reason,
            $this,
            $guardedAttribute,
            $this->getOriginal($guardedAttribute),
            $this->getAttribute($guardedAttribute),
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

        $this->interlockBypassedWrites[] = $bypassedWrite($reason);
    }

    private function runningTransitionWrote(string $stateAttribute): bool
    {
        $running = $this->interlockRunningTransitions[$stateAttribute] ?? null;

        return $running !== null && $this->getAttribute($stateAttribute) === $running->transition->targetState;
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
