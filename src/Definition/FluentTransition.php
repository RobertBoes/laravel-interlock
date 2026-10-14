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
     * Declare who may apply the transition: a closure that receives the actor (null when there is none) and the model
     * and returns a bool or a Gate response, or the name of a Gate ability, checked for the actor with the model. A
     * denied actor makes the transition throw UnauthorisedTransitionException, which renders as 403. Without it,
     * every actor may apply the transition, null included.
     */
    public function authorise(Closure|string $authorisation): self
    {
        $this->authorisation = $authorisation;

        return $this;
    }

    /**
     * Add a guard: a condition on the model that must hold for the transition to apply, such as "stock is available".
     * The check receives the model, unsaved changes included, and returns whether the condition holds. When it does
     * not, the transition throws FailedGuardException with the message, which renders as 422.
     */
    public function guard(string $name, Closure $check, string $message): self
    {
        $this->guards[] = new CompiledGuard($name, $check, $message);

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
     * Apply the transition by itself once a condition holds, such as "`publish_at` has passed". The condition receives
     * the model's query builder and constrains it: `fn(Builder $query) => $query->where('publish_at', '<=', now())`,
     * or any other callable, such as an invokable scope class the app already taps onto its queries.
     * The transition's from-states are added to it, so it only says what is specific to the trigger. Every minute, the
     * scheduled interlock:triggers command applies the transition, on behalf of no one, to each model that matches.
     * Rescheduling means changing the data the condition reads; nothing needs cancelling.
     *
     * Each run applies it to at most `interlock.triggers.max_per_run` models, a chunk at a time. With `fanOut: true`,
     * a run dispatches one job per chunk instead, so a busy trigger can use several queue workers.
     */
    public function trigger(callable $condition, bool $fanOut = false): self
    {
        $this->trigger = $condition(...);
        $this->triggerFansOut = $fanOut;

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
