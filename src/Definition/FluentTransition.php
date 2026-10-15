<?php

namespace RobertBoes\Interlock\Definition;

use BackedEnum;
use Closure;
use RobertBoes\Interlock\Graph\CompiledGuard;

final class FluentTransition
{
    /** @var list<BackedEnum> */
    public private(set) array $fromStates = [];

    public private(set) ?BackedEnum $targetState = null;

    /** @var list<CompiledGuard> */
    public private(set) array $guards = [];

    public private(set) Closure|string|null $authorisation = null;

    public private(set) ?Closure $applyStep = null;

    public private(set) ?Closure $afterCommitStep = null;

    public private(set) ?Closure $trigger = null;

    public private(set) bool $triggerFansOut = false;

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
     * A closure receiving the actor (or null) and the model that returns a bool or a Gate response, or a Gate ability.
     */
    public function authorise(Closure|string $authorisation): self
    {
        $this->authorisation = $authorisation;

        return $this;
    }

    /**
     * The check receives the model, unsaved changes included, and returns whether the guard holds.
     */
    public function guard(string $name, Closure $check, string $message): self
    {
        $this->guards[] = new CompiledGuard($name, $check, $message);

        return $this;
    }

    /**
     * Runs inside the transaction, before the compare-and-swap write; attributes it sets are saved in the same update.
     */
    public function apply(Closure $step): self
    {
        $this->applyStep = $step;

        return $this;
    }

    /**
     * The condition constrains the model's query builder; the from-states are added to it. Matching models get the
     * transition without an actor or authorisation check; its guards still run.
     */
    public function trigger(callable $condition, bool $fanOut = false): self
    {
        $this->trigger = $condition(...);
        $this->triggerFansOut = $fanOut;

        return $this;
    }

    public function afterCommit(Closure $step): self
    {
        $this->afterCommitStep = $step;

        return $this;
    }
}
