<?php

namespace RobertBoes\Interlock\History;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final readonly class HistoryRecord
{
    /**
     * @param  ?string  $transition  null for a bypassed write
     * @param  ?BackedEnum  $fromState  null for a model created in its state inside a bypass
     * @param  array<string, mixed>  $oldAttributes  as stored before the write
     * @param  array<string, mixed>  $newAttributes  as stored after the write
     * @param  ?string  $systemReason  the reason of the Interlock::asSystem() scope it was written in
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
        public ?string $systemReason = null,
    ) {}
}
