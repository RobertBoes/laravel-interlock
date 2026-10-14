<?php

namespace RobertBoes\Interlock\Persistence;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Graph\CompiledTransition;

/**
 * The query a running transition is saved through. Its update only matches the row while the
 * stored state is still the state the model was loaded with (ADR 0004), and throws stale state
 * otherwise, before Eloquent marks the model as saved.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class CompareAndSwapBuilder extends Builder
{
    private function __construct(
        QueryBuilder $query,
        private readonly CompiledTransition $transition,
        private readonly BackedEnum $loadedState,
    ) {
        parent::__construct($query);
    }

    /**
     * @template TSavedModel of Model
     *
     * @param  Builder<TSavedModel>  $saveQuery
     * @return self<TSavedModel>
     */
    public static function replacing(Builder $saveQuery, CompiledTransition $transition, BackedEnum $loadedState): self
    {
        return (new self($saveQuery->getQuery(), $transition, $loadedState))->setModel($saveQuery->getModel());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $storedStateQuery = clone $this;

        $this->where($this->transition->stateAttribute, $this->loadedState->value);
        $affectedRows = parent::update($values);

        if ($affectedRows === 0) {
            $this->ensureStoredStateIsUnchanged($storedStateQuery);
        }

        return $affectedRows;
    }

    /**
     * Zero affected rows is not proof of stale state: MySQL reports changed rows rather than
     * matched rows, so an update that writes the values already stored also affects none.
     *
     * @param  Builder<TModel>  $storedStateQuery
     */
    private function ensureStoredStateIsUnchanged(Builder $storedStateQuery): void
    {
        $stored = $storedStateQuery->toBase()->useWritePdo()->first([$this->transition->stateAttribute]);

        if ($stored === null) {
            throw StaleStateException::rowDeleted($this->model, $this->transition);
        }

        $storedState = $stored->{$this->transition->stateAttribute};

        if ((string) $storedState !== (string) $this->loadedState->value) {
            throw StaleStateException::storedStateChanged($this->model, $this->transition, $this->loadedState, $storedState);
        }
    }
}
