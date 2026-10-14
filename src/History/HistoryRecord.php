<?php

namespace RobertBoes\Interlock\History;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * What one transition, or one bypassed write, changed on one state attribute of a model, handed to the history recorder.
 */
final readonly class HistoryRecord
{
    /**
     * @param  ?string  $transition  the transition's name, or null for a bypassed write
     * @param  ?BackedEnum  $fromState  null for a model created in its state inside a bypass
     * @param  array<string, mixed>  $oldAttributes  the attributes written, as they were stored before
     * @param  array<string, mixed>  $newAttributes  the attributes written, as they are stored now
     * @param  ?string  $bypassReason  the reason of the bypass that allowed a bypassed write
     * @param  ?string  $trigger  the name of the trigger that applied the transition
     */
    public function __construct(
        public Model $model,
        public string $stateAttribute,
        public ?string $transition,
        public ?BackedEnum $fromState,
        public ?BackedEnum $targetState,
        public ?Authenticatable $actor,
        public array $oldAttributes,
        public array $newAttributes,
        public ?string $bypassReason = null,
        public ?string $trigger = null,
    ) {}
}
