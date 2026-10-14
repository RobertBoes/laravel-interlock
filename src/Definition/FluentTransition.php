<?php

namespace RobertBoes\Interlock\Definition;

use BackedEnum;

final class FluentTransition
{
    /** @var list<BackedEnum> */
    public private(set) array $fromStates = [];

    public private(set) ?BackedEnum $targetState = null;

    public function __construct(public readonly string $name) {}

    public function from(BackedEnum ...$states): self
    {
        $this->fromStates = array_values($states);

        return $this;
    }

    public function to(BackedEnum $state): self
    {
        $this->targetState = $state;

        return $this;
    }
}
