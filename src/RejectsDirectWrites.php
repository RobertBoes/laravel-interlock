<?php

namespace RobertBoes\Interlock;

use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Events\BypassedMassWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Graph\ModelGraph;

/**
 * Rejects mass writes to state attributes and guarded attributes through the Eloquent builder of an interlocked model,
 * unless a bypass is active. Add it to the custom builder of an interlocked model; InterlockBuilder uses it.
 *
 * Inserted rows are treated as creating a model: a row may start in an initial state and leave guarded attributes null.
 *
 * @mixin Builder<Model>
 */
trait RejectsDirectWrites
{
    private bool $interlockSavesModel = false;

    /**
     * @internal HasStateMachines checks a model's own save before it writes, so its query is not checked again.
     */
    public function interlockSavesModel(): static
    {
        $this->interlockSavesModel = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        return $this->interlockWrite('update', fn() => parent::update($values), updated: array_keys($values));
    }

    /**
     * @param  array<mixed>  $values  one row, or a list of rows
     * @param  array<mixed>|string  $uniqueBy
     * @param  array<mixed>|null  $update  the columns to update when a row exists, or every column when null
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $rows = self::interlockRowsOf($values);
        $update ??= array_keys($rows[0] ?? []);
        $updated = array_map(fn(mixed $key, mixed $value) => is_int($key) ? $value : $key, array_keys($update), $update);

        return $this->interlockWrite('upsert', fn() => parent::upsert($values, $uniqueBy, $update), $updated, $rows);
    }

    /**
     * @param  mixed  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        return $this->interlockWrite('increment', fn() => parent::increment($column, $amount, $extra), [$column, ...array_keys($extra)]);
    }

    /**
     * @param  mixed  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        return $this->interlockWrite('decrement', fn() => parent::decrement($column, $amount, $extra), [$column, ...array_keys($extra)]);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        return $this->interlockWrite('incrementEach', fn() => parent::incrementEach($columns, $extra), [...array_keys($columns), ...array_keys($extra)]);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        return $this->interlockWrite('decrementEach', fn() => parent::decrementEach($columns, $extra), [...array_keys($columns), ...array_keys($extra)]);
    }

    /**
     * @param  array<mixed>  $values  one row, or a list of rows
     * @return bool
     */
    public function insert(array $values)
    {
        return $this->interlockWrite('insert', fn() => $this->toBase()->insert($values), inserted: self::interlockRowsOf($values));
    }

    /**
     * @param  array<mixed>  $values  one row, or a list of rows
     * @return int
     */
    public function insertOrIgnore(array $values)
    {
        return $this->interlockWrite('insertOrIgnore', fn() => $this->toBase()->insertOrIgnore($values), inserted: self::interlockRowsOf($values));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        return $this->interlockWrite('insertGetId', fn() => $this->toBase()->insertGetId($values, $sequence), inserted: [$values]);
    }

    /**
     * @param  array<mixed>  $values  one row, or a list of rows
     * @param  non-empty-array<non-empty-string>  $returning
     * @param  non-empty-string|non-empty-array<non-empty-string>|null  $uniqueBy
     * @return Collection<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        return $this->interlockWrite('insertOrIgnoreReturning', fn() => $this->toBase()->insertOrIgnoreReturning($values, $returning, $uniqueBy), inserted: self::interlockRowsOf($values));
    }

    /**
     * The values a subquery inserts are not known up front, so a state attribute or guarded attribute among the
     * columns is a direct write, whatever the subquery selects.
     *
     * @param  list<string>  $columns
     * @param  mixed  $query
     * @return int
     */
    public function insertUsing(array $columns, $query)
    {
        return $this->interlockWrite('insertUsing', fn() => $this->toBase()->insertUsing($columns, $query), $columns);
    }

    /**
     * @param  list<string>  $columns
     * @param  mixed  $query
     * @return int
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        return $this->interlockWrite('insertOrIgnoreUsing', fn() => $this->toBase()->insertOrIgnoreUsing($columns, $query), $columns);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        return $this->interlockWrite('updateFrom', fn() => $this->toBase()->updateFrom($values), array_keys($values));
    }

    /**
     * Inserts the row when no row matches the attributes, as creating a model does, and otherwise updates the first
     * row that matches with the values; checked as that insert or that update.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|(Closure(bool): array<string, mixed>)  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|Closure $values = [])
    {
        $query = $this->toBase();
        $exists = $query->where($attributes)->exists();
        $values = $values instanceof Closure ? $values($exists) : $values;

        if (! $exists) {
            $row = [...$attributes, ...$values];

            return $this->interlockWrite('updateOrInsert', fn() => $query->insert($row), inserted: [$row]);
        }

        if ($values === []) {
            return true;
        }

        return $this->interlockWrite('updateOrInsert', fn() => (bool) $query->limit(1)->update($values), updated: array_keys($values));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @param  list<mixed>  $updated  the columns the write sets, whatever their values
     * @param  list<array<mixed>>  $inserted  the rows the write inserts
     * @return TResult
     */
    private function interlockWrite(string $method, Closure $write, array $updated = [], array $inserted = []): mixed
    {
        if ($this->interlockSavesModel) {
            return $write();
        }

        $graph = Container::getInstance()->make(GraphRepository::class)->for($this->model);
        $written = array_values(array_unique([
            ...array_map(self::interlockAttributeOf(...), array_filter($updated, is_string(...))),
            ...self::interlockDirectlyInserted($graph, $inserted),
        ]));
        $stateAttributes = array_values(array_intersect($written, array_keys($graph->machines)));
        $guardedAttributes = array_values(array_intersect($written, array_keys($graph->guardedAttributes)));

        if ($stateAttributes === [] && $guardedAttributes === []) {
            return $write();
        }

        $reason = Container::getInstance()->make(BypassScope::class)->reason()
            ?? throw DirectWriteException::throughBuilder($this->model, $method, $stateAttributes, $guardedAttributes);

        $result = $write();

        Event::dispatch(new BypassedMassWrite($reason, $this->model::class, $method, [...$stateAttributes, ...$guardedAttributes]));

        return $result;
    }

    /**
     * The attributes the rows write as no model could be created: a state other than an initial state, or a guarded attribute that is set.
     *
     * @param  list<array<mixed>>  $rows
     * @return list<string>
     */
    private static function interlockDirectlyInserted(ModelGraph $graph, array $rows): array
    {
        $written = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                if (! is_string($column)) {
                    continue;
                }

                $attribute = self::interlockAttributeOf($column);

                if (self::interlockInsertsDirectly($graph, $attribute, $value)) {
                    $written[] = $attribute;
                }
            }
        }

        return $written;
    }

    private static function interlockInsertsDirectly(ModelGraph $graph, string $attribute, mixed $value): bool
    {
        $machine = $graph->machines[$attribute] ?? null;

        if ($machine === null) {
            return $value !== null;
        }

        return ! array_any(
            $machine->initialStates,
            fn(BackedEnum $initialState) => self::interlockIsState($value, $initialState),
        );
    }

    /**
     * Whether the value is the state, as a case or as its stored value.
     */
    private static function interlockIsState(mixed $value, BackedEnum $state): bool
    {
        if ($value instanceof BackedEnum) {
            return $value === $state;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        return (string) $value === (string) $state->value;
    }

    /**
     * @param  array<mixed>  $values  one row, or a list of rows
     * @return list<array<mixed>>
     */
    private static function interlockRowsOf(array $values): array
    {
        $rows = is_array(Arr::first($values)) ? $values : [$values];

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * The attribute a column of a write refers to, such as [status] for "orders.status" or [meta] for "meta->colour".
     * The table is ignored: SQLite writes a column of a joined table to the model's own table.
     */
    private static function interlockAttributeOf(string $column): string
    {
        return Str::afterLast(Str::before($column, '->'), '.');
    }
}
