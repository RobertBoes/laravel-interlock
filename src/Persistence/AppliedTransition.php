<?php

namespace RobertBoes\Interlock\Persistence;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\History\HistoryRecord;

/**
 * A staged transition whose guards held and whose apply step ran, with the attributes it wrote.
 */
final readonly class AppliedTransition
{
    /**
     * @param  array<string, string>  $attributes  the transition's own writes: its state attribute and the attributes its apply step set, keyed by themselves
     * @param  array<string, mixed>  $oldAttributes  those attributes as they were stored before
     */
    public function __construct(
        public StagedTransition $staged,
        public array $attributes,
        public array $oldAttributes,
    ) {}

    /**
     * The transition's history, read from the model once it is saved.
     */
    public function historyRecord(Model $model): HistoryRecord
    {
        return new HistoryRecord(
            model: $model,
            stateAttribute: $this->staged->transition->stateAttribute,
            transition: $this->staged->transition->name,
            fromState: $this->staged->fromState,
            targetState: $this->staged->transition->targetState,
            actor: $this->staged->actor,
            oldAttributes: $this->oldAttributes,
            newAttributes: array_map(fn(string $attribute) => $model->getAttributes()[$attribute] ?? null, $this->attributes),
            trigger: $this->staged->trigger,
        );
    }
}
