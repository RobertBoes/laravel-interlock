<?php

namespace RobertBoes\Interlock\Scopes;

use BackedEnum;
use Carbon\CarbonInterface;
use DateInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use RobertBoes\Interlock\Exceptions\InvalidStateQueryException;

/**
 * Matches the models whose entered-at column is at or before now minus the duration, worked out when the query runs,
 * so a machine definition that is compiled and cached never freezes a date. Without states it constrains only the time.
 */
final readonly class InStateFor
{
    /** @var list<BackedEnum> */
    private array $states;

    /**
     * @param  BackedEnum|list<BackedEnum>  $states
     */
    public function __construct(
        BackedEnum|array $states = [],
        private ?DateInterval $duration = null,
        private int $days = 0,
        private int $hours = 0,
        private int $minutes = 0,
        private ?string $stateAttribute = null,
    ) {
        $this->states = is_array($states) ? $states : [$states];

        if ($duration === null && $days === 0 && $hours === 0 && $minutes === 0) {
            throw InvalidStateQueryException::noDuration();
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>|Relation<TModel, *, *>  $query
     */
    public function __invoke(Builder|Relation $query): void
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        $model = $builder->getModel();
        $machine = MachineToQuery::of($model, $this->states, $this->stateAttribute);
        $column = $machine->enteredAt ?? throw InvalidStateQueryException::noEnteredAtColumn($model, $machine);

        if ($this->states !== []) {
            $builder->whereIn(
                $builder->qualifyColumn($machine->stateAttribute),
                MachineToQuery::leafValues($model, $machine, $this->states),
            );
        }

        $builder->where($builder->qualifyColumn($column), '<=', $model->fromDateTime($this->cutoff()));
    }

    private function cutoff(): CarbonInterface
    {
        $cutoff = Date::now()->subDays($this->days)->subHours($this->hours)->subMinutes($this->minutes);

        return $this->duration === null ? $cutoff : $cutoff->sub($this->duration);
    }
}
