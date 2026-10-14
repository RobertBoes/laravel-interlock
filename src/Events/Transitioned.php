<?php

namespace RobertBoes\Interlock\Events;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

final readonly class Transitioned
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public string $transition,
        public BackedEnum $fromState,
        public BackedEnum $targetState,
    ) {}
}
