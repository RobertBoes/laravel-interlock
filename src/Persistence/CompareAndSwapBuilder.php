<?php

namespace RobertBoes\Interlock\Persistence;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RobertBoes\Interlock\Exceptions\StaleStateException;

/**
 * The query running transitions are saved through. Its update only matches the row while the
 * stored state of every transitioned state attribute is still the state the transition started
 * from (ADR 0004), and throws stale state otherwise, before Eloquent marks the model as saved.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class CompareAndSwapBuilder extends Builder
{
    /**
     * @param  non-empty-array<string, StagedTransition>  $stagedTransitions  keyed by state attribute
     * @param  Closure(): void  $written  called once the update wrote the target states
     */
    private function __construct(QueryBuilder $query, private readonly array $stagedTransitions, private readonly Closure $written)
    {
        parent::__construct($query);
    }

    /**
     * @template TSavedModel of Model
     *
     * @param  Builder<TSavedModel>  $saveQuery
     * @param  non-empty-array<string, StagedTransition>  $stagedTransitions  keyed by state attribute
     * @param  Closure(): void  $written  called once the update wrote the target states, before Eloquent's updated event
     * @return self<TSavedModel>
     */
    public static function replacing(Builder $saveQuery, array $stagedTransitions, Closure $written): self
    {
        return (new self($saveQuery->getQuery(), $stagedTransitions, $written))->setModel($saveQuery->getModel());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $storedStateQuery = clone $this;

        foreach ($this->stagedTransitions as $stateAttribute => $staged) {
            $this->where($stateAttribute, $staged->fromState->value);
        }

        $affectedRows = parent::update($values);

        if ($affectedRows === 0) {
            $this->ensureTheUpdateMatchedTheRow($storedStateQuery);
        }

        ($this->written)();

        return $affectedRows;
    }

    /**
     * Zero affected rows means the update matched no row, unless every transition is a self-transition: MySQL reports
     * changed rather than matched rows, so an update that writes the values already stored also affects none. Only then
     * does the stored row decide whether the state is stale; otherwise it only tells why.
     *
     * The read locks the row: inside a transaction under MySQL's REPEATABLE READ, a plain read returns the snapshot the
     * transaction took at its first read, while the update matched against the latest committed row.
     *
     * @param  Builder<TModel>  $storedStateQuery
     */
    private function ensureTheUpdateMatchedTheRow(Builder $storedStateQuery): void
    {
        $stored = $storedStateQuery->toBase()->useWritePdo()->lockForUpdate()->first(array_keys($this->stagedTransitions));

        if ($stored === null) {
            $staged = array_values($this->stagedTransitions)[0];

            throw StaleStateException::rowDeleted($this->model, $staged->compiled, $staged->fromState);
        }

        foreach ($this->stagedTransitions as $stateAttribute => $staged) {
            $storedState = $stored->{$stateAttribute};

            if ((string) $storedState !== (string) $staged->fromState->value) {
                throw StaleStateException::storedStateChanged($this->model, $staged->compiled, $staged->fromState, $storedState);
            }
        }

        foreach ($this->stagedTransitions as $stateAttribute => $staged) {
            if ($staged->fromState !== $staged->compiled->targetState) {
                throw StaleStateException::storedStateChanged($this->model, $staged->compiled, $staged->fromState, $stored->{$stateAttribute});
            }
        }
    }
}
