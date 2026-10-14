<?php

namespace RobertBoes\Interlock\Definition;

use BackedEnum;
use Closure;

final class FluentTransition
{
    /** @var list<BackedEnum> */
    public private(set) array $fromStates = [];

    public private(set) ?BackedEnum $targetState = null;

    public private(set) ?Closure $applyStep = null;

    public private(set) ?Closure $afterCommitStep = null;

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

    /**
     * Run a step inside the transition's transaction, before the compare-and-swap write. The closure receives the
     * model; attributes it sets are saved in the same update, and rows it writes roll back when the transition fails.
     */
    public function apply(Closure $step): self
    {
        $this->applyStep = $step;

        return $this;
    }

    /**
     * Run a step once the transition has committed. The closure receives the model.
     */
    public function afterCommit(Closure $step): self
    {
        $this->afterCommitStep = $step;

        return $this;
    }
}
