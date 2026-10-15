<?php

namespace RobertBoes\Interlock;

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
use RobertBoes\Interlock\Exceptions\CancelledTransitionException;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\MissingBuilderTraitException;
use RobertBoes\Interlock\Exceptions\NotAFromStateException;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Graph\ModelGraph;
use RobertBoes\Interlock\Graph\TransitionSteps;
use RobertBoes\Interlock\History\HistoryEntry;
use RobertBoes\Interlock\History\HistoryRecord;
use RobertBoes\Interlock\History\HistoryRecorder;
use RobertBoes\Interlock\Persistence\AppliedTransition;
use RobertBoes\Interlock\Persistence\CompareAndSwapBuilder;
use RobertBoes\Interlock\Persistence\DirectWriteCheck;
use RobertBoes\Interlock\Persistence\StagedTransition;
use RobertBoes\Interlock\Persistence\UnsavedModel;
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
     * Apply a transition by name, `transition('publish')`, or as an instance of its class, `transition(new Ship($trackingCode))`.
     *
     * The actor is the authenticated user, unless one is given: `transition('approve', $manager)`. Pass null to apply
     * it on behalf of no one; authorisation still decides. Transitions staged on the model's other machines are written
     * with it.
     * When a saving or updating listener cancels the save, nothing is written and it throws.
     */
    public function transition(string|Transition $transition, ?Authenticatable $actor = null): static
    {
        $compiled = $this->interlockStageTransition($transition, func_num_args() > 1 ? $actor : $this->interlockAuthenticatedActor(), null);
        $this->interlockSaveOrThrowWhenCancelled($compiled);

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
        $this->interlockStageTransition($transition, func_num_args() > 1 ? $actor : $this->interlockAuthenticatedActor(), null);

        return $this;
    }

    /**
     * @internal Applies the transition as its trigger does: without an actor and without checking its authorisation,
     * though its guards still run, recorded under the trigger's name.
     */
    public function interlockApplyTrigger(string $transition): void
    {
        $this->interlockSaveOrThrowWhenCancelled($this->interlockStageTransition($transition, null, $transition));
    }

    private function interlockSaveOrThrowWhenCancelled(CompiledTransition $transition): void
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
            throw NotAFromStateException::currentState($this, $compiled, $fromState);
        }

        $alreadyStaged = $this->interlockStagedTransitions[$compiled->stateAttribute] ?? null;

        if ($alreadyStaged !== null) {
            throw IllegalTransitionException::anotherTransitionStaged($this, $compiled, $alreadyStaged->compiled);
        }

        $steps = $this->interlockStepsToRun($compiled, $transition);

        $this->interlockStagedTransitions[$compiled->stateAttribute] = new StagedTransition($compiled, $steps, $fromState, $actor, $trigger);
        $this->setAttribute($compiled->stateAttribute, $compiled->targetState);

        return $compiled;
    }

    /**
     * The transition's steps, or none while transitions are faked. They are built while faked too, so a transition
     * that cannot be built from its name still throws.
     */
    private function interlockStepsToRun(CompiledTransition $compiled, string|Transition $transition): TransitionSteps
    {
        $steps = $compiled->steps($transition instanceof Transition ? $transition : null);

        if (Container::getInstance()->make(TransitionLog::class)->faking()) {
            return TransitionSteps::none();
        }

        return $steps;
    }

    /**
     * Drop the staged transitions without saving, putting each state attribute back to the state it was staged from.
     */
    public function discardStagedTransitions(): static
    {
        $this->interlockRevertToFromStates($this->interlockStagedTransitions);
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

        $stagedTransitions = $this->interlockStagedTransitions;
        $this->interlockStagedTransitions = [];
        $unsaved = UnsavedModel::of($this);

        try {
            $committed = $this->interlockWriteTransitions($stagedTransitions, $options);
        } catch (Throwable $exception) {
            $this->interlockRestoreUnsavedModel($unsaved, $stagedTransitions);

            throw $exception;
        }

        if (! $committed) {
            $this->interlockRestoreUnsavedModel($unsaved, $stagedTransitions);

            return false;
        }

        $this->getConnection()->afterCommit(fn() => $this->interlockFinishCommittedTransitions($stagedTransitions));

        return true;
    }

    /**
     * Runs each committed transition's after-commit step and dispatches its Transitioned event. One that throws, or
     * whose listener throws, keeps neither its own event nor the later transitions from running: the transitions have
     * committed, so each one finishes, and the first failure is rethrown once they all have.
     *
     * @param  array<string, StagedTransition>  $stagedTransitions
     */
    private function interlockFinishCommittedTransitions(array $stagedTransitions): void
    {
        $log = Container::getInstance()->make(TransitionLog::class);
        $failures = [];

        foreach ($stagedTransitions as $staged) {
            $log->record($this, $staged->compiled->name);

            $failures[] = $this->interlockFailureOf(fn() => $staged->steps->afterCommit($this));
            $failures[] = $this->interlockFailureOf(fn() => Event::dispatch(
                new Transitioned($this, $staged->compiled->name, $staged->fromState, $staged->compiled->targetState),
            ));
        }

        $firstFailure = array_find($failures, fn(?Throwable $failure) => $failure !== null);

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function interlockFailureOf(Closure $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
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
        $actor = func_num_args() > 0 ? $actor : $this->interlockAuthenticatedActor();

        return new Collection($this->interlockGraph()->transitions())
            ->filter(fn(CompiledTransition $transition) => $transition->isAvailable($this, $actor))
            ->map(fn(CompiledTransition $transition) => new AvailableTransition($transition->name, $transition->stateAttribute, $transition->targetState))
            ->values();
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
                $copy->setAttribute($machine->stateAttribute, $machine->soleInitialState());
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
     * Authorises every actor, except for a transition its trigger applies, then checks each transition's
     * guards and runs its apply step, and saves them all through one compare-and-swap update and records their history,
     * in one transaction that rolls back when a guard fails or the save fails or is cancelled. Guards and authorisation
     * see the state each transition starts from, plus the changes of the transitions staged before it.
     *
     * @param  array<string, StagedTransition>  $stagedTransitions
     * @param  array<string, mixed>  $options
     */
    private function interlockWriteTransitions(array $stagedTransitions, array $options): bool
    {
        foreach ($stagedTransitions as $stateAttribute => $staged) {
            if ($this->getAttribute($stateAttribute) !== $staged->compiled->targetState) {
                throw DirectWriteException::toStagedStateAttribute($this, $staged->compiled, $this->getAttribute($stateAttribute));
            }
        }

        $this->interlockRevertToFromStates($stagedTransitions);

        foreach ($stagedTransitions as $staged) {
            if ($staged->trigger === null) {
                $staged->steps->authorise($this, $staged->actor, $staged->compiled);
            }
        }

        $connection = $this->getConnection();
        $connection->beginTransaction();

        $this->interlockRunningTransitions = $stagedTransitions;

        try {
            $appliedTransitions = array_map($this->interlockApplyTransition(...), $stagedTransitions);

            if (! parent::save($options)) {
                $connection->rollBack();

                return false;
            }

            foreach ($appliedTransitions as $applied) {
                $this->interlockRecordHistory($applied->historyRecord($this));
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        } finally {
            $this->interlockRunningTransitions = [];
            $this->interlockRunningTransitionsWritten = false;
        }

        return true;
    }

    /**
     * Checks the transition's guards, moves its state attribute to the target state and runs its apply step.
     */
    private function interlockApplyTransition(StagedTransition $staged): AppliedTransition
    {
        $compiled = $staged->compiled;

        $staged->steps->checkGuards($this, $compiled);
        $this->setAttribute($compiled->stateAttribute, $compiled->targetState);
        $attributesBeforeApply = $this->getAttributes();
        $staged->steps->apply($this);
        $attributes = $this->interlockAttributesWrittenSince($attributesBeforeApply, $compiled->stateAttribute);
        $applied = new AppliedTransition($staged, $attributes, array_map(fn(string $attribute) => $this->getRawOriginal($attribute), $attributes));

        Event::dispatch(new Transitioning($this, $compiled->name, $staged->fromState, $compiled->targetState));

        return $applied;
    }

    /**
     * The transition's own writes: its state attribute and the attributes its apply step set, keyed by themselves.
     *
     * @param  array<string, mixed>  $attributesBeforeApply
     * @return array<string, string>
     */
    private function interlockAttributesWrittenSince(array $attributesBeforeApply, string $stateAttribute): array
    {
        $changed = array_filter(
            $this->getAttributes(),
            fn(mixed $value, string $attribute) => ! array_key_exists($attribute, $attributesBeforeApply) || $attributesBeforeApply[$attribute] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
        $written = [$stateAttribute, ...array_keys($changed)];

        return array_combine($written, $written);
    }

    /**
     * Puts the model back as it was before a failed save, without the transitions it tried to write. The save may
     * have synced the original attributes before it failed, while the database rolled back, so they are put back too.
     *
     * @param  array<string, StagedTransition>  $stagedTransitions
     */
    private function interlockRestoreUnsavedModel(UnsavedModel $unsaved, array $stagedTransitions): void
    {
        $this->setRawAttributes($unsaved->attributes);
        $this->original = $unsaved->original;
        $this->changes = $unsaved->changes;
        $this->wasRecentlyCreated = $unsaved->wasRecentlyCreated;

        $this->interlockRevertToFromStates($stagedTransitions);
    }

    /**
     * Puts each state attribute back to the state its transition was staged from.
     *
     * @param  array<string, StagedTransition>  $stagedTransitions
     */
    private function interlockRevertToFromStates(array $stagedTransitions): void
    {
        foreach ($stagedTransitions as $stateAttribute => $staged) {
            $this->setAttribute($stateAttribute, $staged->fromState);
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
     * Running transitions are saved through a compare-and-swap query. Once it ran, a save nested
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

        return $this->interlockWriteRecordingBypassedWrites(fn() => parent::performUpdate($query));
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        $query = $this->interlockModelSaveQuery($query);

        return $this->interlockWriteRecordingBypassedWrites(fn() => parent::performInsert($query));
    }

    /**
     * Records the history of the write's bypassed writes, in one transaction with the write: one entry for each machine
     * whose state attribute, or one of whose guarded attributes, the write bypassed. A running transition's write is
     * in the transition's transaction already. A write that fails drops its bypassed writes, so a later save that
     * writes nothing does not dispatch them.
     *
     * @param  Closure(): bool  $write
     */
    private function interlockWriteRecordingBypassedWrites(Closure $write): bool
    {
        $writeAndRecord = function () use ($write): bool {
            if (! $write()) {
                return false;
            }

            foreach ($this->interlockBypassedWritesByStateAttribute() as $stateAttribute => [$reason, $attributes]) {
                $this->interlockRecordHistory(new HistoryRecord(
                    model: $this,
                    stateAttribute: $stateAttribute,
                    transition: null,
                    fromState: $this->getOriginal($stateAttribute),
                    targetState: $this->getAttribute($stateAttribute),
                    actor: $this->interlockAuthenticatedActor(),
                    oldAttributes: array_map(fn(string $attribute) => $this->getRawOriginal($attribute), $attributes),
                    newAttributes: array_map(fn(string $attribute) => $this->getAttributes()[$attribute] ?? null, $attributes),
                    bypassReason: $reason,
                ));
            }

            return true;
        };

        try {
            $written = $this->interlockRunningTransitions === [] && $this->interlockMayMakeABypassedWriteThatRecordsHistory()
                ? $this->getConnection()->transaction($writeAndRecord)
                : $writeAndRecord();
        } catch (Throwable $exception) {
            $this->interlockBypassedWrites = [];

            throw $exception;
        }

        if (! $written) {
            $this->interlockBypassedWrites = [];
        }

        return $written;
    }

    /**
     * The bypassed writes grouped by the machine they belong to: a state attribute's own, or the machines declaring a
     * guarded attribute.
     *
     * @return array<string, array{string, array<string, string>}> the reason and the attributes written, keyed by state attribute
     */
    private function interlockBypassedWritesByStateAttribute(): array
    {
        $graph = $this->interlockGraph();
        $grouped = [];

        foreach ($this->interlockBypassedWrites as $bypassedWrite) {
            [$attribute, $stateAttributes] = $bypassedWrite instanceof BypassedWrite
                ? [$bypassedWrite->stateAttribute, [$bypassedWrite->stateAttribute]]
                : [$bypassedWrite->attribute, $graph->machinesGuarding($bypassedWrite->attribute)];

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
    private function interlockMayMakeABypassedWriteThatRecordsHistory(): bool
    {
        if (! $this->interlockGraph()->recordsAnyHistory()) {
            return false;
        }

        return Container::getInstance()->make(BypassScope::class)->reason() !== null;
    }

    private function interlockRecordHistory(HistoryRecord $record): void
    {
        if ($this->interlockGraph()->recordsHistory($record->stateAttribute)) {
            Container::getInstance()->make(HistoryRecorder::class)->record($record);
        }
    }

    /**
     * Interlocked models get Interlock's builder unless they set a custom builder, which must use RejectsDirectWrites.
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
     * Every query the model builds starts here, so a custom builder that lacks RejectsDirectWrites fails as soon as one
     * is built, every time, before it can make a mass write. Checked here rather than in newEloquentBuilder(),
     * which a model may override, and not on instantiation, so making a model needs no database connection.
     *
     * @return Builder<static>
     */
    public function newModelQuery()
    {
        /** @var Builder<static> $builder */
        $builder = parent::newModelQuery();
        $this->interlockRejectBuilderWithoutTrait($builder);

        return $builder;
    }

    /**
     * @param  Builder<*>  $builder
     */
    private function interlockRejectBuilderWithoutTrait(Builder $builder): void
    {
        if (isset(self::$interlockCheckedBuilders[static::class])) {
            return;
        }

        if (! in_array(RejectsDirectWrites::class, class_uses_recursive($builder), true)) {
            throw MissingBuilderTraitException::on($this, $builder);
        }

        self::$interlockCheckedBuilders[static::class] = true;
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
     * Called by performUpdate() after the updating event, whether or not model events are muted.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        $this->interlockBypassedWrites = [];
        $this->interlockDirectWriteCheck()->checkUpdate($this->interlockRunningTransitions);

        return parent::getDirtyForUpdate();
    }

    /**
     * Called by performInsert() after the creating event, whether or not model events are muted.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert(): array
    {
        $this->interlockBypassedWrites = [];
        $this->interlockDirectWriteCheck()->checkCreate();

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

    private function interlockDirectWriteCheck(): DirectWriteCheck
    {
        return new DirectWriteCheck($this, $this->interlockGraph(), function (BypassedWrite|BypassedGuardedAttributeWrite $bypassedWrite) {
            $this->interlockBypassedWrites[] = $bypassedWrite;
        });
    }

    private function interlockAuthenticatedActor(): ?Authenticatable
    {
        return Container::getInstance()->make(AuthFactory::class)->guard()->user();
    }

    private function interlockGraph(): ModelGraph
    {
        return Container::getInstance()->make(GraphRepository::class)->for($this);
    }
}
