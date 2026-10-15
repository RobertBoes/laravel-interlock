<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Most often a form submitted twice. Laravel renders an HTTP exception's message even with debug off, so the message
 * stays generic; the details are on its properties and in context().
 */
final class NotAFromStateException extends RuntimeException implements InterlockException, HttpExceptionInterface
{
    /**
     * @param  list<BackedEnum>  $fromStates  as declared
     */
    private function __construct(
        public readonly Model $model,
        public readonly string $transition,
        public readonly string $stateAttribute,
        public readonly ?BackedEnum $currentState,
        public readonly array $fromStates,
    ) {
        parent::__construct('This transition can\'t be applied to the record in its current state.');
    }

    public static function currentState(Model $model, CompiledTransition $transition, ?BackedEnum $currentState): self
    {
        return new self($model, $transition->name, $transition->stateAttribute, $currentState, $transition->fromStates);
    }

    /**
     * @return array{model: class-string<Model>, key: mixed, transition: string, state_attribute: string, current_state: int|string|null, from_states: list<int|string>}
     */
    public function context(): array
    {
        return [
            'model' => $this->model::class,
            'key' => $this->model->getKey(),
            'transition' => $this->transition,
            'state_attribute' => $this->stateAttribute,
            'current_state' => $this->currentState?->value,
            'from_states' => array_map(fn(BackedEnum $state) => $state->value, $this->fromStates),
        ];
    }

    public function getStatusCode(): int
    {
        return 409;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
