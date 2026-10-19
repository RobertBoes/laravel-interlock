<?php

namespace RobertBoes\Interlock\History;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;

/**
 * The history table keeps every model's key in one string column, so models with integer, UUID and ULID keys share
 * it. Keys are compared as strings: compared with an integer, MySQL casts every row and skips the index, and Postgres
 * refuses the comparison.
 *
 * @internal
 *
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<HistoryEntry, TDeclaringModel>
 */
final class HistoryRelation extends MorphMany
{
    /**
     * @return ?string
     */
    public function getParentKey()
    {
        return self::asString(parent::getParentKey());
    }

    /**
     * @param  Builder<HistoryEntry>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  mixed  $columns
     * @return Builder<HistoryEntry>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $grammar = $query->getQuery()->getGrammar();
        $parentKey = self::castToString($grammar, $grammar->wrap($this->getQualifiedParentKeyName()));

        return $query->select($columns)
            // @phpstan-ignore argument.type (built only from identifiers the grammar wrapped)
            ->whereRaw(new Expression($grammar->wrap($this->getExistenceCompareKey()) . ' = ' . $parentKey))
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }

    /**
     * @param  string  $key
     */
    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  ?string  $key
     * @return array<int, ?string>
     */
    protected function getKeys(array $models, $key = null)
    {
        return array_map(self::asString(...), parent::getKeys($models, $key));
    }

    private static function asString(mixed $key): ?string
    {
        return $key === null ? null : (string) $key;
    }

    private static function castToString(Grammar $grammar, string $column): string
    {
        return match (true) {
            $grammar instanceof PostgresGrammar, $grammar instanceof SQLiteGrammar => "cast({$column} as text)",
            $grammar instanceof SqlServerGrammar => "cast({$column} as nvarchar(36))",
            default => "cast({$column} as char)",
        };
    }
}
