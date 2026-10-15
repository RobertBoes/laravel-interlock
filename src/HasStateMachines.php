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
use RobertBoes\Interlock\Exceptions\TriggerOnlyTransitionException;
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
    /** @var array<string, StagedTransition> */
    private array $interlockStagedTransitions = [];

    /** @var array<string, StagedTransition> */
    private array $interlockRunningTransitions = [];

    private bool $interlockRunningTransitionsWritten = false;

    /** @var list<BypassedWrite|BypassedGuardedAttributeWrite> */
    private array $interlockBypassedWrites = [];

    /** @var array<class-string<Model>, true> */
    private static array $interlockCheckedBuilders = [];

    /**
     * The actor defaults to the authenticated user; pass null to apply it on behalf of no one.
     */
    public function transition(string|Transition $transition, ?Authenticatable $actor = null): static
    {
        $compiled = $this->interlockStageTransition($transition, func_num_args() > 1 ? $actor : $this->interlockAuthenticatedActor(), null);
        $this->interlockSaveOrThrowWhenCancelled($compiled);

        return $this;
    }

    /**
     * Only the graph is checked now; authorization, guards and the write run on the next save().
     */
    public function stageTransition(string|Transition $transition, ?Authenticatable $actor = null): static
    {
        $this->interlockStageTransition($transition, func_num_args() > 1 ? $actor : $this->interlockAuthenticatedActor(), null);

        return $this;
    }

    /**
     * @internal Applies it without an actor or an authorization check; its guards still run.
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
     * Built even while faked, so a transition that cannot be built from its name still throws.
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
     * Puts each state attribute back to the state it was staged from.
     */
    public function discardStagedTransitions(): static
    {
        $this->interlockRevertToFromStates($this->interlockStagedTransitions);
        $this->interlockStagedTransitions = [];

        return $this;
    }

    /**
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
     * The transitions have committed, so each one finishes even when another throws; the first failure is rethrown.
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
     * @return $this
     */
    public function refresh(): static
    {
        parent::refresh();

        $this->interlockStagedTransitions = [];

        return $this;
    }

    /**
     * Pass null to ask on behalf of no one. A transition that takes input is offered on its authorization alone.
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
     * The copy starts its lifecycle over in an initial state with guarded attributes cleared, unless a replicating
     * listener sets them.
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
     * Unordered; entries recorded in the same second share a timestamp, so order a timeline by key.
     *
     * @return MorphMany<HistoryEntry, $this>
     */
    public function history(?string $stateAttribute = null): MorphMany
    {
        $history = $this->morphMany(HistoryEntry::class, 'model');

        return $stateAttribute === null ? $history : $history->where('state_attribute', $stateAttribute);
    }

    /**
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
            if ($staged->trigger !== null) {
                continue;
            }

            if ($staged->compiled->triggerOnly) {
                throw TriggerOnlyTransitionException::appliedByHand($this, $staged->compiled);
            }

            $staged->steps->authorize($this, $staged->actor, $staged->compiled);
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
     * The failed save may have synced the original attributes while the database rolled back, so they are restored too.
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
     * Once the compare-and-swap update ran, a nested save from an observer or apply step writes through the plain
     * query: the row is in the target state already, and the transaction holds it.
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
     * A running transition's write is in its transaction already. A failed write drops its bypassed writes, so a
     * later save that writes nothing does not dispatch them.
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
     * Not decided by what is dirty: this runs before the updating or creating listeners, which may still set a state
     * or guarded attribute.
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
     * @param  QueryBuilder  $query
     * @return Builder<*>
     */
    public function newEloquentBuilder($query)
    {
        $builder = parent::newEloquentBuilder($query);

        return $builder::class === Builder::class ? new InterlockBuilder($query) : $builder;
    }

    /**
     * Checked here rather than in newEloquentBuilder(), which a model may override, and not on instantiation, so
     * making a model needs no database connection.
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
