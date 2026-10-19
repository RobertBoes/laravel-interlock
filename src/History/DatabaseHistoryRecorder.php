<?php

namespace RobertBoes\Interlock\History;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\MissingHistoryTableException;
use RobertBoes\Interlock\Graph\GraphRepository;
use WeakMap;

/**
 * Writes on the model's own connection, so the entry joins the write's transaction.
 */
final class DatabaseHistoryRecorder implements HistoryRecorder
{
    /** @var WeakMap<ConnectionInterface, true> */
    private WeakMap $connectionsWithTable;

    public function __construct(
        private readonly GraphRepository $graphs,
    ) {
        $this->connectionsWithTable = new WeakMap();
    }

    public function record(HistoryRecord $record): void
    {
        $entry = new HistoryEntry([
            'model_type' => $record->model->getMorphClass(),
            'model_id' => (string) $record->model->getKey(),
            'state_attribute' => $record->stateAttribute,
            'transition_name' => $record->transition,
            'from_state' => $record->fromState?->value,
            'target_state' => $record->targetState?->value,
            'actor_type' => match (true) {
                $record->actor instanceof Model => $record->actor->getMorphClass(),
                $record->actor !== null => $record->actor::class,
                default => null,
            },
            'actor_id' => $record->actor?->getAuthIdentifier(),
            'system_reason' => $record->systemReason,
            'old_attributes' => $record->oldAttributes,
            'new_attributes' => $record->newAttributes,
            'bypass_reason' => $record->bypassReason,
            'trigger_name' => $record->trigger,
        ]);

        $entry->setConnection($record->model->getConnectionName());
        $this->ensureTableExists($entry, $record);
        $entry->save();
    }

    /**
     * Checked up front rather than by catching the insert's failure: on Postgres a failed statement aborts the
     * transaction. Only a table that exists is remembered, so a long-running worker sees the migration once it has run.
     */
    private function ensureTableExists(HistoryEntry $entry, HistoryRecord $record): void
    {
        $connection = $entry->getConnection();

        if (isset($this->connectionsWithTable[$connection])) {
            return;
        }

        if (! $connection->getSchemaBuilder()->hasTable($entry->getTable())) {
            throw MissingHistoryTableException::on(
                $connection->getName(),
                $entry->getTable(),
                $this->graphs->for($record->model)->machines[$record->stateAttribute]->class,
            );
        }

        $this->connectionsWithTable[$connection] = true;
    }
}
