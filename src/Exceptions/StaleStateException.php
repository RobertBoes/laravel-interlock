<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use RobertBoes\Interlock\Graph\CompiledTransition;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Laravel renders an HTTP exception's message even with debug off, so the message stays generic; the details are on
 * its properties and in context().
 */
final class StaleStateException extends RuntimeException implements InterlockException, HttpExceptionInterface
{
    private function __construct(
        public readonly Model $model,
        public readonly string $transition,
        public readonly string $stateAttribute,
        public readonly BackedEnum $loadedState,
        public readonly int|string|null $storedState,
        public readonly bool $rowDeleted,
    ) {
        parent::__construct('This record changed since it was loaded. Reload it and try again.');
    }

    public static function storedStateChanged(Model $model, CompiledTransition $transition, BackedEnum $loadedState, int|string|null $storedState): self
    {
        return new self($model, $transition->name, $transition->stateAttribute, $loadedState, $storedState, rowDeleted: false);
    }

    public static function rowDeleted(Model $model, CompiledTransition $transition, BackedEnum $loadedState): self
    {
        return new self($model, $transition->name, $transition->stateAttribute, $loadedState, null, rowDeleted: true);
    }

    /**
     * @return array{model: class-string<Model>, key: mixed, transition: string, state_attribute: string, loaded_state: int|string, stored_state: int|string|null, row_deleted: bool}
     */
    public function context(): array
    {
        return [
            'model' => $this->model::class,
            'key' => $this->model->getKey(),
            'transition' => $this->transition,
            'state_attribute' => $this->stateAttribute,
            'loaded_state' => $this->loadedState->value,
            'stored_state' => $this->storedState,
            'row_deleted' => $this->rowDeleted,
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
