<?php

namespace RobertBoes\Interlock\Graph;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Transition;

/**
 * The steps one application of a transition runs: a fluent transition's closures, or a transition object's methods.
 */
final readonly class TransitionSteps
{
    private function __construct(
        private ?Closure $apply,
        private ?Closure $afterCommit,
    ) {}

    public static function closures(?Closure $apply, ?Closure $afterCommit): self
    {
        return new self($apply, $afterCommit);
    }

    public static function of(Transition $transition): self
    {
        return new self(
            method_exists($transition, 'apply') ? $transition->apply(...) : null,
            method_exists($transition, 'afterCommit') ? $transition->afterCommit(...) : null,
        );
    }

    public function apply(Model $model): void
    {
        if ($this->apply !== null) {
            ($this->apply)($model);
        }
    }

    public function afterCommit(Model $model): void
    {
        if ($this->afterCommit !== null) {
            ($this->afterCommit)($model);
        }
    }
}
