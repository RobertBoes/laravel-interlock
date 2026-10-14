<?php

namespace RobertBoes\Interlock\History;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes history to the interlock_history table, on the model's own connection so it joins the write's transaction.
 */
final class DatabaseHistoryRecorder implements HistoryRecorder
{
    public function record(HistoryRecord $record): void
    {
        $entry = new HistoryEntry([
            'model_type' => $record->model->getMorphClass(),
            'model_id' => $record->model->getKey(),
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
            'old_attributes' => $record->oldAttributes,
            'new_attributes' => $record->newAttributes,
            'bypass_reason' => $record->bypassReason,
            'trigger_name' => $record->trigger,
        ]);

        $entry->setConnection($record->model->getConnectionName())->save();
    }
}
