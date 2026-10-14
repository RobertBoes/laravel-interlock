<?php

namespace RobertBoes\Interlock\Events;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

final readonly class Transitioned
{
    public function __construct(
        public Model $model,
        public string $transition,
        public BackedEnum $fromState,
        public BackedEnum $targetState,
    ) {}
}
