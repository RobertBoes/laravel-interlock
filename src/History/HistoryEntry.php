<?php

namespace RobertBoes\Interlock\History;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * States are stored as their backing values, and the written attributes as they are stored on the model's row.
 *
 * @property string $model_type
 * @property int|string $model_id
 * @property string $state_attribute
 * @property ?string $transition_name null for a bypassed write
 * @property int|string|null $from_state null for a model created in its state inside a bypass
 * @property int|string|null $target_state
 * @property ?string $actor_type
 * @property int|string|null $actor_id
 * @property array<string, mixed> $old_attributes
 * @property array<string, mixed> $new_attributes
 * @property ?string $bypass_reason
 * @property ?string $trigger_name
 * @property ?Carbon $created_at
 */
class HistoryEntry extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'interlock_history';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'old_attributes' => 'array',
            'new_attributes' => 'array',
        ];
    }
}
