<?php

namespace RobertBoes\Interlock\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * A model as it was before a save that writes staged transitions, so a failed save can put it back.
 */
final readonly class UnsavedModel
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $original  the original attributes, uncast
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
