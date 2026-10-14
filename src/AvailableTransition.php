<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A transition the actor can apply to the model right now, as returned by `$model->availableTransitions($actor)`.
 * It serialises as its name and the value of its target state, ready for an API resource or Inertia props.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class AvailableTransition implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $name,
        public BackedEnum $targetState,
    ) {}

    /**
     * @return array{name: string, target_state: int|string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'target_state' => $this->targetState->value];
    }

    /**
     * @return array{name: string, target_state: int|string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
