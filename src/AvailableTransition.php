<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A transition the actor can apply to the model right now, as returned by `$model->availableTransitions($actor)`.
 * It serialises as its name, the state attribute of its machine and the value of its target state, ready for an API
 * resource or Inertia props.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class AvailableTransition implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $name,
        public string $stateAttribute,
        public BackedEnum $targetState,
    ) {}

    /**
     * @return array{name: string, state_attribute: string, target_state: int|string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'state_attribute' => $this->stateAttribute,
            'target_state' => $this->targetState->value,
        ];
    }

    /**
     * @return array{name: string, state_attribute: string, target_state: int|string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
