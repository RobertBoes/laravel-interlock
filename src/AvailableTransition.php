<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, int|string>
 */
final readonly class AvailableTransition implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $name,
        public string $signal,
        public string $stateAttribute,
        public BackedEnum $targetState,
    ) {}

    /**
     * @return array{name: string, signal: string, state_attribute: string, target_state: int|string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'signal' => $this->signal,
            'state_attribute' => $this->stateAttribute,
            'target_state' => $this->targetState->value,
        ];
    }

    /**
     * @return array{name: string, signal: string, state_attribute: string, target_state: int|string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
