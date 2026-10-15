<?php

namespace RobertBoes\Interlock\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Kept so a failed save can put the model back.
 */
final readonly class UnsavedModel
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public array $attributes,
        public array $original,
        public array $changes,
        public bool $wasRecentlyCreated,
    ) {}

    public static function of(Model $model): self
    {
        return new self($model->getAttributes(), $model->getRawOriginal(), $model->getChanges(), $model->wasRecentlyCreated);
    }
}
