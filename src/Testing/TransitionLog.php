<?php

namespace RobertBoes\Interlock\Testing;

use Illuminate\Database\Eloquent\Model;

/**
 * The transitions committed in this app instance, for Interlock's test assertions, and whether Interlock is faked.
 * Bound as a singleton, so a fresh app instance (the next test) starts empty and unfaked. It only records while
 * tests run, so long-running workers never accumulate transitions.
 */
final class TransitionLog
{
    /** @var list<array{model: Model, transition: string}> */
    private array $transitions = [];

    private bool $faking = false;

    public function __construct(private readonly bool $recording) {}

    public function record(Model $model, string $transition): void
    {
        if ($this->recording) {
            $this->transitions[] = ['model' => $model, 'transition' => $transition];
        }
    }

    /**
     * The names of the transitions committed for the model's row, through this instance or any other, oldest first.
     *
     * @return list<string>
     */
    public function transitionsOf(Model $model): array
    {
        return array_values(array_map(
            fn(array $entry) => $entry['transition'],
            array_filter($this->transitions, fn(array $entry) => $entry['model']->is($model)),
        ));
    }

    public function fake(): void
    {
        $this->faking = true;
    }

    public function faking(): bool
    {
        return $this->faking;
    }
}
