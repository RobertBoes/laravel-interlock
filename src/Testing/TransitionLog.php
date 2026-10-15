<?php

namespace RobertBoes\Interlock\Testing;

use Illuminate\Database\Eloquent\Model;

/**
 * A singleton, so each test's fresh app instance starts empty and unfaked. It only records while tests run, so
 * long-running workers never accumulate transitions.
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
